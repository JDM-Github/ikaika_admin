<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Email signatures. A signature is a footer (email_blocks kind 'footer') whose body carries the
 * $SIGNATURE token; rendering replaces that token with the employee's own details. The plain footers
 * (no token) stay usable in email but are never offered as a signature. The employee's own footer is
 * chosen per person (employee_signatures.footer_id), falling back to the first token footer.
 */
final class PortalSignature
{
    private const TOKEN = '$SIGNATURE';

    // The dynamic tokens a footer may place where the person's own details go. A footer carrying any
    // of them (or $SIGNATURE for the whole block) is a signature footer.
    public const PLACEHOLDERS = ['name', 'title', 'email', 'phone', 'department', 'location'];

    // The Active Directory tokens the Exchange transport rule uses, one per placeholder, so a single
    // rule reads each sender's own details and covers the whole company.
    private const AD_TOKENS = [
        'name' => '%%DisplayName%%',
        'title' => '%%Title%%',
        'email' => '%%WindowsEmailAddress%%',
        'phoneNumber' => '%%Phone%%',
        'department' => '%%Department%%',
        'location' => '%%City%%',
    ];

    public function __construct(private readonly PortalEmployeeSignature $employees) {}

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
        $name = trim((string) preg_replace('/[^\w \-]/', '', (string) $this->employees->values($employee)['name']));
        if ($name === '') {
            $name = 'IKAIKA Signature';
        }

        return [
            'fileName' => 'Install-IKAIKA-Signature.cmd',
            'script' => $this->cmdScript($name, base64_encode($document['html'])),
        ];
    }

    // The .cmd carries the signature base64 and a PowerShell one-liner: write the .htm, then set the
    // default signature on every Office version present. Idempotent, so re-running updates it.
    private function cmdScript(string $name, string $base64): string
    {
        $command = '$dir = Join-Path $env:APPDATA \'Microsoft\\Signatures\'; '
            .'New-Item -ItemType Directory -Force -Path $dir | Out-Null; '
            .'$path = Join-Path $dir ($env:IKAIKA_SIG + \'.htm\'); '
            .'[IO.File]::WriteAllBytes($path, [Convert]::FromBase64String($env:IKAIKA_B64)); '
            .'foreach ($v in @(\'16.0\',\'15.0\')) { '
            .'$k = \'HKCU:\\Software\\Microsoft\\Office\\\' + $v + \'\\Common\\MailSettings\'; '
            .'if (Test-Path $k) { '
            .'Set-ItemProperty -Path $k -Name \'NewSignature\' -Value $env:IKAIKA_SIG -ErrorAction SilentlyContinue; '
            .'Set-ItemProperty -Path $k -Name \'ReplySignature\' -Value $env:IKAIKA_SIG -ErrorAction SilentlyContinue } }; '
            .'Write-Host (\'Signature installed for \' + $env:IKAIKA_SIG)';

        return "@echo off\r\n"
            ."setlocal\r\n"
            .'set "IKAIKA_SIG='.$name."\"\r\n"
            .'set "IKAIKA_B64='.$base64."\"\r\n"
            .'powershell -NoProfile -ExecutionPolicy Bypass -Command "'.$command.'"'."\r\n"
            ."echo.\r\n"
            ."echo Done. Open Outlook and your signature is ready.\r\n"
            ."pause\r\n";
    }

    /**
     * A PowerShell script for IT to create the org-wide Exchange transport rule: the company signature
     * appended server-side, so it is locked (users cannot edit it) and reaches every client, including
     * the new Outlook, which no local file can. The person's fields come from Active Directory tokens,
     * so one rule covers everyone. Null when no signature footer is set.
     *
     * @return array{fileName: string, script: string}|null
     */
    public function transportRule(): ?array
    {
        $footer = $this->defaultFooter();
        if ($footer === null) {
            return null;
        }

        return [
            'fileName' => 'Install-IKAIKA-Signature-Rule.ps1',
            'script' => $this->transportRuleScript($this->renderBody($footer, self::AD_TOKENS)),
        ];
    }

    // The rule is replaced if it already exists, so re-running updates the signature in place. A
    // single-quoted here-string leaves the HTML untouched; Exchange caps the text at 5000 characters.
    private function transportRuleScript(string $html): string
    {
        return "\$ErrorActionPreference = 'Stop'\r\n"
            ."# Creates the org-wide IKAIKA email signature as an Exchange transport rule. Server-side,\r\n"
            ."# so users cannot edit it and the new Outlook is covered. Run as an Exchange Online admin\r\n"
            ."# (Connect-ExchangeOnline first). Add -SentToScope NotInOrganization to limit it to outside mail.\r\n"
            ."\$rule = 'IKAIKA email signature'\r\n"
            ."\$html = @'\r\n"
            .$html."\r\n"
            ."'@\r\n"
            ."if (\$html.Length -gt 5000) { throw ('The signature is ' + \$html.Length + ' characters; Exchange allows 5000.') }\r\n"
            ."if (Get-TransportRule -Identity \$rule -ErrorAction SilentlyContinue) { Remove-TransportRule -Identity \$rule -Confirm:\$false }\r\n"
            ."New-TransportRule -Name \$rule -FromScope InOrganization -ApplyHtmlDisclaimerLocation Append -ApplyHtmlDisclaimerFallbackAction Wrap -ApplyHtmlDisclaimerText \$html\r\n"
            ."Write-Host ('Transport rule \"' + \$rule + '\" is in place.')\r\n";
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

    // The employee's chosen footer when it is a token footer, else the first token footer.
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

    // The company signature footer every unassigned person falls back to: the first token footer.
    private function defaultFooter(): ?object
    {
        foreach ($this->footerRows() as $row) {
            if ($this->hasToken((string) $row->body)) {
                return $row;
            }
        }

        return null;
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
}
