<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Email signatures. A signature is a footer (email_blocks kind 'footer') whose body carries the
 * $SIGNATURE token; rendering replaces that token with the employee's own details. The plain footers
 * (no token) stay usable in email but are never offered as a signature. The employee's own footer is
 * chosen per person (employee_signatures.footer_id), falling back to the default footer: the one chosen
 * on Manage / Signatures, else the oldest token footer.
 */
final class PortalSignature
{
    private const TOKEN = '$SIGNATURE';

    // The dynamic tokens a footer may place where the person's own details go. A footer carrying any
    // of them (or $SIGNATURE for the whole block) is a signature footer.
    public const PLACEHOLDERS = ['name', 'title', 'email', 'phone', 'department', 'location'];

    // Each person's Exchange rule is this prefix plus their id, so a re-run updates it in place and
    // removes the rules of people no longer active. The first version made one rule under LEGACY_RULE.
    private const RULE_PREFIX = 'IKAIKA signature #';

    private const LEGACY_RULE = 'IKAIKA email signature';

    // The chosen default footer, a portal knob in the core settings table; unset means the oldest.
    private const DEFAULT_SETTING = 'signature.default_footer';

    public function __construct(
        private readonly PortalEmployeeSignature $employees,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * The id of the footer everyone without their own assigned footer gets; null when none exists.
     */
    public function defaultFooterId(): ?string
    {
        $footer = $this->defaultFooter();

        return $footer === null ? null : (string) (int) $footer->id;
    }

    /**
     * Makes a signature footer the default. A plain footer (no placeholder) cannot be one, because it
     * would render nobody's details.
     */
    public function setDefault(Employee $actor, int $footerId, Request $request): string
    {
        $footer = $footerId > 0
            ? $this->connection()->table('email_blocks')->where('id', $footerId)->where('kind', 'footer')->first(['id', 'name', 'body'])
            : null;
        if ($footer === null) {
            abort(404, 'That footer was not found.');
        }
        if (! $this->hasToken((string) $footer->body)) {
            abort(422, 'Only a signature footer, one with a placeholder like {name}, can be the default.');
        }

        $this->coreConnection()->table('settings')->updateOrInsert(
            ['product' => 'portal', 'setting_key' => self::DEFAULT_SETTING],
            ['setting_value' => (string) $footerId],
        );
        $name = trim((string) $footer->name);
        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            PortalEmail::CORE_RESOURCE,
            PortalActivityCopy::madeDefaultSignature($name),
            (string) $footerId,
            $request,
            ['defaultSignature' => $footerId, 'name' => $name],
        );

        return (string) $footerId;
    }

    // Whether a footer body is usable as a signature: it carries $SIGNATURE or a {placeholder},
    // ignoring style/script blocks and comments, where braces appear for other reasons.
    public static function carriesSignature(string $body): bool
    {
        $scan = (string) preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $body);
        $scan = (string) preg_replace('/<!--.*?-->/s', '', $scan);
        if (str_contains(strtoupper($scan), self::TOKEN)) {
            return true;
        }
        foreach (self::PLACEHOLDERS as $key) {
            if (stripos($scan, '{'.$key.'}') !== false) {
                return true;
            }
        }

        return false;
    }

    // How a footer body reads for one person, keeping its own format: placeholders filled, so the
    // same footer can ride under an email and match whoever sends it. Used by the email composer.
    public function fill(Employee $employee, string $body, string $format): string
    {
        $values = $this->employees->values($employee);
        $isHtml = strtolower(trim($format)) === 'html';
        $map = [
            '{name}' => $values['name'],
            '{title}' => $values['title'],
            '{email}' => $values['email'],
            '{phone}' => $values['phoneNumber'],
            '{department}' => $values['department'],
            '{location}' => $values['location'],
        ];
        foreach ($map as $token => $value) {
            $replacement = $isHtml ? htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') : (string) $value;
            $body = str_ireplace($token, $replacement, $body);
        }
        if (! $isHtml) {
            return $body;
        }
        $body = str_ireplace(self::TOKEN, $this->detailsHtml($values), $body);

        return $this->tidy($body);
    }

    // How a footer body reads for the editor's sample person, as finished HTML, before it is saved.
    public function preview(Employee $employee, string $body, string $format): string
    {
        return $this->renderBody((object) ['body' => $body, 'format' => $format], $this->employees->values($employee));
    }

    /**
     * A one-click installer for Windows Outlook: a .cmd that writes the signature .htm into the
     * Signatures folder and sets it as the default for new mail and replies. Unsigned for now, so
     * Windows and antivirus may warn before it runs. Null when no signature footer is set.
     *
     * @return array{fileName: string, script: string}|null
     */
    public function installer(Employee $employee): ?array
    {
        $document = $this->document($employee);
        if ($document === null) {
            return null;
        }
        $values = $this->employees->values($employee);
        $name = trim((string) preg_replace('/[^\w \-]/', '', (string) $values['name']));
        if ($name === '') {
            $name = 'IKAIKA Signature';
        }
        // The address the per-account registry key is matched on; cmd-safe, so odd characters cannot break the file.
        $email = trim((string) preg_replace('/["%&<>|^]/', '', (string) $values['email']));

        return [
            'fileName' => 'Install-IKAIKA-Signature.cmd',
            'script' => $this->cmdScript($name, $email, base64_encode($document['html'])),
        ];
    }

    // The .cmd carries the signature base64 and a PowerShell one-liner: write the .htm, then set the
    // default on the account whose address matches (keeping Outlook's own value type), falling back to
    // the all-accounts default when no profile matches. Idempotent, so re-running updates it.
    private function cmdScript(string $name, string $email, string $base64): string
    {
        $command = '$sig=$env:IKAIKA_SIG; $email=$env:IKAIKA_EMAIL; '
            .'$dir=Join-Path $env:APPDATA \'Microsoft\Signatures\'; '
            .'New-Item -ItemType Directory -Force -Path $dir|Out-Null; '
            .'$path=Join-Path $dir ($sig+\'.htm\'); '
            .'[IO.File]::WriteAllBytes($path,[Convert]::FromBase64String($env:IKAIKA_B64)); '
            .'function T($v){ if($v -is [byte[]]){[Text.Encoding]::Unicode.GetString($v).Trim([char]0)} elseif($v -is [string]){$v.Trim([char]0)} else {$null} }; '
            .'$noise=\'PSPath\',\'PSParentPath\',\'PSChildName\',\'PSDrive\',\'PSProvider\'; $hit=$false; '
            .'foreach($ver in @(\'16.0\',\'15.0\')){ '
            .'$profiles=\'HKCU:\Software\Microsoft\Office\\\'+$ver+\'\Outlook\Profiles\'; '
            .'if(-not(Test-Path $profiles)){continue}; '
            .'foreach($prof in Get-ChildItem $profiles){ '
            .'$root=Join-Path $prof.PSPath \'9375CFF0413111d3B88A00104B2A6676\'; '
            .'if(-not(Test-Path $root)){continue}; '
            .'foreach($acct in Get-ChildItem $root){ '
            .'$texts=(Get-ItemProperty $acct.PSPath).PSObject.Properties|Where-Object{$_.Name -notin $noise}|ForEach-Object{T $_.Value}|Where-Object{$_}; '
            .'if($texts -contains $email){ '
            .'foreach($n in @(\'New Signature\',\'Reply-Forward Signature\')){ '
            .'$kind=\'String\'; try{$kind=(Get-Item $acct.PSPath).GetValueKind($n).ToString()}catch{}; '
            .'if($kind -eq \'Binary\'){ Set-ItemProperty $acct.PSPath $n ([Text.Encoding]::Unicode.GetBytes($sig+[char]0)) -Type Binary } '
            .'else { Set-ItemProperty $acct.PSPath $n $sig -Type String } }; '
            .'$hit=$true } } } }; '
            .'if(-not $hit){ '
            .'foreach($v in @(\'16.0\',\'15.0\')){ '
            .'$k=\'HKCU:\Software\Microsoft\Office\\\'+$v+\'\Common\MailSettings\'; '
            .'if(Test-Path $k){ '
            .'Set-ItemProperty $k \'NewSignature\' $sig -ErrorAction SilentlyContinue; '
            .'Set-ItemProperty $k \'ReplySignature\' $sig -ErrorAction SilentlyContinue } } }; '
            .'Write-Host (\'Signature installed for \'+$sig)';

        return "@echo off\r\n"
            ."setlocal\r\n"
            .'set "IKAIKA_SIG='.$name."\"\r\n"
            .'set "IKAIKA_EMAIL='.$email."\"\r\n"
            .'set "IKAIKA_B64='.$base64."\"\r\n"
            .'powershell -NoProfile -ExecutionPolicy Bypass -Command "'.$command.'"'."\r\n"
            ."echo.\r\n"
            ."echo Done. Open Outlook and your signature is ready.\r\n"
            ."pause\r\n";
    }

    /**
     * A PowerShell script for IT that sets the company signature as Exchange transport rules: one rule
     * per active employee, each carrying that person's signature exactly as Settings shows it (their own
     * footer, their portal details), so nothing has to be filled in Microsoft 365. Server-side, so it is
     * locked and reaches every client, including the new Outlook. Null when no signature footer is set
     * or no active employee has an email address.
     *
     * @return array{fileName: string, script: string}|null
     */
    public function transportRule(): ?array
    {
        if ($this->defaultFooter() === null) {
            return null;
        }

        $people = [];
        $employees = Employee::query()->whereRaw("LOWER(COALESCE(status, '')) = 'active'")->orderBy('id')->get();
        foreach ($employees as $employee) {
            $values = $this->employees->values($employee);
            $email = strtolower(trim((string) $values['email']));
            // Exchange matches the sender's address, so a missing or shared one cannot carry a rule.
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($people[$email])) {
                continue;
            }
            $footer = $this->footerFor($employee);
            if ($footer === null) {
                continue;
            }
            $people[$email] = [
                'name' => mb_substr(self::RULE_PREFIX.$employee->getKey().' ('.$email.')', 0, 64),
                'email' => $email,
                'marker' => $this->replyMarker((string) $footer->body),
                'html' => $this->ruleHtml($this->renderBody($footer, $values)),
            ];
        }
        if ($people === []) {
            return null;
        }

        return [
            'fileName' => 'Install-IKAIKA-Signature-Rules.ps1',
            'script' => $this->transportRuleScript(array_values($people)),
        ];
    }

    // Exchange takes the signature as one string: whitespace runs collapse (they render the same, and the
    // reply marker then matches the text), and anything outside ASCII becomes an entity, so Windows
    // PowerShell reading the script as ANSI cannot garble it.
    private function ruleHtml(string $html): string
    {
        $html = trim((string) preg_replace('/\s+/u', ' ', $html));

        return mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    }

    // The first sentence of the footer's longest fixed text (usually the confidentiality notice). The
    // rule skips a message that already contains it, so a reply in a thread carrying the signature does
    // not stack another copy at the bottom. Cut at the first non-ASCII character to stay ANSI-safe, and
    // empty when too short to be distinctive.
    private function replyMarker(string $body): string
    {
        $scan = (string) preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $body);
        $scan = (string) preg_replace('/<!--.*?-->/s', '', $scan);
        $longest = '';
        foreach (preg_split('/<[^>]+>/', $scan) ?: [] as $segment) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($segment, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (! self::carriesSignature($text) && mb_strlen($text) > mb_strlen($longest)) {
                $longest = $text;
            }
        }
        $sentence = (string) (preg_split('/(?<=[.!?])\s/u', $longest)[0] ?? '');
        $sentence = (string) preg_replace('/[^\x20-\x7E].*$/su', '', $sentence);
        if (strlen($sentence) > 100) {
            $sentence = (string) preg_replace('/\s+\S*$/', '', substr($sentence, 0, 101));
        }
        $sentence = trim($sentence, ' .!?,;:');

        return strlen($sentence) >= 20 ? $sentence : '';
    }

    /**
     * Each person becomes a hashtable with their rule name, address, reply marker and signature HTML in a
     * single-quoted here-string (left untouched by PowerShell). The script keeps its window open, so the
     * person running it can read the result, and names a missing admin role instead of a vague failure.
     *
     * @param  list<array{name: string, email: string, marker: string, html: string}>  $people
     */
    private function transportRuleScript(array $people): string
    {
        $quote = static fn (string $value): string => "'".str_replace("'", "''", $value)."'";
        $entries = '';
        foreach ($people as $person) {
            $entries .= "        @{\n"
                .'            Name = '.$quote($person['name'])."\n"
                .'            Email = '.$quote($person['email'])."\n"
                .'            Marker = '.$quote($person['marker'])."\n"
                ."            Html = @'\n".$person['html']."\n'@\n"
                ."        }\n";
        }

        $script = <<<'PS'
# Sets the IKAIKA email signature in Exchange Online: one mail flow rule per person, each carrying
# that person's signature exactly as the portal shows it. Exchange adds it after Send, so nobody can
# edit it, and every Outlook (the new one included) shows it.
#
# Run it as a Microsoft 365 admin (Exchange Administrator or Global Administrator role): right-click
# the file, Run with PowerShell, and sign in when the browser opens. Run it again whenever someone
# joins, leaves or changes their details; it updates the rules in place.
#
# People in this file: __COUNT__. A message that already contains the signature's notice (a reply in
# a thread that has it) is skipped, so copies do not pile up. Meeting invites are skipped too.

$ErrorActionPreference = 'Stop'
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    # 3.7.0 is the first version with -DisableWAM, used below.
    $module = Get-Module -ListAvailable -Name ExchangeOnlineManagement | Sort-Object Version -Descending | Select-Object -First 1
    if (-not $module -or $module.Version -lt [version]'3.7.0') {
        Write-Host 'Installing the Exchange Online module (one time)...'
        Install-Module ExchangeOnlineManagement -MinimumVersion 3.7.0 -Scope CurrentUser -Force -AllowClobber
    }
    Import-Module ExchangeOnlineManagement -MinimumVersion 3.7.0
    Write-Host 'Signing in to Exchange Online (a browser window opens)...'
    # WAM, the default sign-in on Windows, can return without connecting; the browser flow is reliable.
    Connect-ExchangeOnline -DisableWAM -ShowBanner:$false
    $account = (Get-ConnectionInformation | Where-Object { $_.State -eq 'Connected' } | Select-Object -First 1).UserPrincipalName
    if (-not $account) {
        throw 'Not signed in to Exchange Online. Run: Connect-ExchangeOnline -Device  then run this script again.'
    }
    # Exchange only loads the commands an account may use, so a missing one means a missing role.
    if (-not (Get-Command New-TransportRule -ErrorAction SilentlyContinue)) {
        throw ($account + ' is signed in but may not manage mail flow rules. It needs the Exchange Administrator or Global Administrator role.')
    }
    Write-Host ('Signed in as ' + $account)

    $people = @(
__PEOPLE__    )

    $existing = @(Get-TransportRule)
    $wanted = @($people | ForEach-Object { $_.Name })
    foreach ($rule in $existing) {
        if (($rule.Name -like '__PREFIX__*' -and $wanted -notcontains $rule.Name) -or $rule.Name -eq '__LEGACY__') {
            Remove-TransportRule -Identity $rule.Identity -Confirm:$false
            Write-Host ('Removed ' + $rule.Name)
        }
    }

    # A narrow role may allow rules without recipient lookups; then every address is tried as it is.
    $canLookUp = [bool](Get-Command Get-Recipient -ErrorAction SilentlyContinue)
    $done = 0
    foreach ($person in $people) {
        if ($canLookUp) {
            $mailbox = $null
            try { $mailbox = Get-Recipient -Identity $person.Email -ErrorAction Stop } catch { }
            if (-not $mailbox) {
                Write-Warning ('Skipped ' + $person.Email + ': no mailbox with that address in Microsoft 365.')
                continue
            }
        }
        if ($person.Html.Length -gt 5000) {
            Write-Warning ('Skipped ' + $person.Email + ': the signature is ' + $person.Html.Length + ' characters; Exchange allows 5000.')
            continue
        }
        $settings = @{
            From = $person.Email
            ApplyHtmlDisclaimerText = $person.Html
            ApplyHtmlDisclaimerLocation = 'Append'
            # A message Exchange cannot change (encrypted or signed) goes out as it is, not wrapped.
            ApplyHtmlDisclaimerFallbackAction = 'Ignore'
            ExceptIfMessageTypeMatches = 'Calendaring'
        }
        if ($person.Marker) {
            $settings.ExceptIfSubjectOrBodyContainsWords = $person.Marker
        }
        if ($existing | Where-Object { $_.Name -eq $person.Name }) {
            if (-not $person.Marker) {
                $settings.ExceptIfSubjectOrBodyContainsWords = $null
            }
            Set-TransportRule -Identity $person.Name @settings
        }
        else {
            New-TransportRule -Name $person.Name @settings | Out-Null
        }
        $done++
        Write-Host ('Signature set for ' + $person.Email)
    }

    Write-Host ''
    Write-Host ('Done: ' + $done + ' of ' + $people.Count + ' people. It can take up to 30 minutes to reach outgoing mail.') -ForegroundColor Green
}
catch {
    Write-Host ''
    Write-Host ('FAILED: ' + $_.Exception.Message) -ForegroundColor Red
}
finally {
    try { Disconnect-ExchangeOnline -Confirm:$false | Out-Null } catch { }
    Read-Host 'Press Enter to close'
}
PS;

        // The people go in last, so their signature text is never searched for the other placeholders.
        $script = str_replace(
            ['__COUNT__', '__PREFIX__', '__LEGACY__', '__PEOPLE__'],
            [(string) count($people), self::RULE_PREFIX, self::LEGACY_RULE, $entries],
            $script,
        );

        return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $script));
    }

    /**
     * The footers that can be a signature (their body carries the token), oldest first.
     *
     * @return list<array{id: string, name: string}>
     */
    public function footers(): array
    {
        $rows = [];
        foreach ($this->footerRows() as $row) {
            if ($this->hasToken((string) $row->body)) {
                $rows[] = ['id' => (string) (int) $row->id, 'name' => trim((string) $row->name)];
            }
        }

        return $rows;
    }

    /**
     * The employee's rendered signature; null when no token footer exists.
     *
     * @return array{brand: string, footerId: string, html: string}|null
     */
    public function render(Employee $employee): ?array
    {
        $footer = $this->footerFor($employee);
        if ($footer === null) {
            return null;
        }

        return [
            'brand' => trim((string) $footer->name),
            'footerId' => (string) (int) $footer->id,
            'html' => $this->renderBody($footer, $this->employees->values($employee)),
        ];
    }

    /**
     * The employee's signature as a standalone .htm document for Outlook. Null when none is set.
     *
     * @return array{fileName: string, html: string}|null
     */
    public function document(Employee $employee): ?array
    {
        $rendered = $this->render($employee);
        if ($rendered === null) {
            return null;
        }
        $name = (string) $this->employees->values($employee)['name'];
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), '-');
        $fileName = 'signature'.($slug === '' ? '' : '-'.$slug).'.htm';
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'</title></head>'
            .'<body style="margin:0;padding:12px;background:#ffffff;">'.$rendered['html'].'</body></html>';

        return ['fileName' => $fileName, 'html' => $html];
    }

    // The employee's chosen footer when it is a token footer, else the default footer.
    private function footerFor(Employee $employee): ?object
    {
        $assignedId = (int) ($this->connection()->table('employee_signatures')->where('employee_id', $employee->getKey())->value('footer_id') ?? 0);
        if ($assignedId > 0) {
            $row = $this->connection()->table('email_blocks')->where('id', $assignedId)->where('kind', 'footer')->first(['id', 'name', 'body', 'format']);
            if ($row !== null && $this->hasToken((string) $row->body)) {
                return $row;
            }
        }

        return $this->defaultFooter();
    }

    // The footer every unassigned person falls back to: the one chosen on Manage / Signatures while it
    // still exists and carries a placeholder, otherwise the oldest token footer.
    private function defaultFooter(): ?object
    {
        $chosen = (int) ($this->coreConnection()->table('settings')
            ->where('product', 'portal')->where('setting_key', self::DEFAULT_SETTING)->value('setting_value') ?? 0);
        $oldest = null;
        foreach ($this->footerRows() as $row) {
            if (! $this->hasToken((string) $row->body)) {
                continue;
            }
            if ((int) $row->id === $chosen) {
                return $row;
            }
            $oldest ??= $row;
        }

        return $oldest;
    }

    /**
     * @return iterable<object>
     */
    private function footerRows(): iterable
    {
        return $this->connection()->table('email_blocks')->where('kind', 'footer')->orderBy('id')->get(['id', 'name', 'body', 'format']);
    }

    private function hasToken(string $body): bool
    {
        return self::carriesSignature($body);
    }

    /**
     * @param  array{name: ?string, title: ?string, email: ?string, phoneNumber: ?string, department: ?string, location: ?string}  $values
     */
    private function renderBody(object $footer, array $values): string
    {
        $body = (string) $footer->body;
        $format = strtolower(trim((string) $footer->format));
        $html = $format === 'html' ? $body : PortalEmailMarkup::html($body);

        // $SIGNATURE drops the whole details block; the {placeholder}s place one value each.
        $html = str_ireplace(self::TOKEN, $this->detailsHtml($values), $html);
        $map = [
            '{name}' => $values['name'],
            '{title}' => $values['title'],
            '{email}' => $values['email'],
            '{phone}' => $values['phoneNumber'],
            '{department}' => $values['department'],
            '{location}' => $values['location'],
        ];
        foreach ($map as $token => $value) {
            $html = str_ireplace($token, htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'), $html);
        }

        return $this->tidy($html);
    }

    // An empty placeholder leaves two separators in a row (an icon between them), and one dangling
    // before a closing tag. Collapse a run into a single separator of the same kind, and drop the
    // dangling one. An icon span counts as padding, so its orphaned separator goes with it.
    private function tidy(string $html): string
    {
        $icon = '<span\b[^>]*>[^<]*<\/span>';
        $pad = '(?:(?:&nbsp;|\s)|'.$icon.')*';
        $html = (string) preg_replace('/(&middot;|·|\|)(?:'.$pad.'(?1))+/u', '$1', $html);

        return (string) preg_replace('/'.$pad.'(?:&middot;|·|\|)'.$pad.'(?=<\/|$)/iu', '', $html);
    }

    /**
     * @param  array{name: ?string, title: ?string, email: ?string, phoneNumber: ?string, department: ?string, location: ?string}  $values
     */
    private function detailsHtml(array $values): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $join = static function (array $parts): string {
            $kept = array_values(array_filter($parts, static fn (?string $part): bool => $part !== null && trim($part) !== ''));

            return implode(' · ', $kept);
        };

        $rows = '<div style="font-size:15px;font-weight:700;color:#111827;">'.$e($values['name']).'</div>';
        $title = $join([$values['title'], $values['department']]);
        if ($title !== '') {
            $rows .= '<div style="font-size:12px;color:#4b5563;">'.$e($title).'</div>';
        }
        $contact = $join([$values['email'], $values['phoneNumber']]);
        if ($contact !== '') {
            $rows .= '<div style="font-size:12px;color:#111827;">'.$e($contact).'</div>';
        }
        if ($values['location'] !== null) {
            $rows .= '<div style="font-size:12px;color:#4b5563;">'.$e($values['location']).'</div>';
        }

        return $rows;
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }

    private function coreConnection(): Connection
    {
        return Model::getConnectionResolver()->connection('core');
    }
}
