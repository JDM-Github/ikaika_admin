<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Company signature in the footers' design (2026-10-13): the logo on its white tile, a divider,
 * the person's name, title and contacts, and the company on the right behind a pale divider with the
 * website and mailbox icons, the notice under a rule. Same words and placeholders as before; only the
 * structure and fonts change, and it stays well under Exchange's 5000-character rule limit. The HTML
 * is database/seeders/email/company-signature.html, inlined in sql/portal/separate.sql; this swaps it
 * in for a signature that is still exactly the previous built-in, so one someone rewrote is left alone.
 */
return new class extends Migration
{
    public const OLD = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;border-collapse:collapse;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#374151;">
  <tr>
    <!-- Logo -->
    <td width="95" valign="middle" style="width:95px;padding:2px 4px 2px 0;border-right:2px solid #1f4e8c;">
      <img src="https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_112,f_auto,q_auto/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png" alt="IKAIKA BIM" width="82" style="display:block;width:82px;max-width:82px;height:auto;border:0;outline:none;text-decoration:none;" />
    </td>
    <!-- Information -->
    <td valign="middle" style="padding:2px 0 2px 14px;">
      <!-- Name -->
      <div style="font-size:18px;line-height:22px;font-weight:700;color:#1f4e8c;">{name}</div>
      <!-- Position -->
      <div style="font-size:13px;line-height:18px;color:#64748b;">{title}&nbsp;&middot;&nbsp;{department}</div>
      <!-- Personal Contact -->
      <div style="font-size:12px;line-height:18px;color:#475569;white-space:nowrap;">
        <a href="mailto:{email}" style="color:#475569;text-decoration:none;">{email}</a>
        &nbsp;&middot;&nbsp;{phone}
        &nbsp;&middot;&nbsp;{location}
      </div>
      <!-- Company -->
      <div style="margin-top:8px;">
        <div style="font-size:15px;line-height:19px;font-weight:700;color:#1f4e8c;">IKAIKA BIM</div>
        <div style="font-size:12px;line-height:17px;white-space:nowrap;">
          <a href="https://ikaikabim.com" style="color:#64748b;text-decoration:none;">ikaikabim.com</a>
          &nbsp;&middot;&nbsp;
          <a href="mailto:info@ikaikabim.com" style="color:#64748b;text-decoration:none;">info@ikaikabim.com</a>
        </div>
      </div>
    </td>
  </tr>
  <!-- Divider -->
  <tr>
    <td colspan="2" style="padding-top:12px;border-bottom:1px solid #d1d9e2;font-size:0;line-height:0;">&nbsp;</td>
  </tr>
  <!-- Confidentiality Notice -->
  <tr>
    <td colspan="2" style="padding-top:6px;font-size:10px;line-height:14px;color:#94a3b8;">
      This message may contain confidential information and is meant only for the people it is addressed to. If you received it by mistake, please let the sender know and delete it.
    </td>
  </tr>
</table>
HTML;

    public const NEW = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <tr>
    <td class="pl-foot-col" valign="middle" style="padding:6px 26px 18px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <td class="pl-foot-logo-cell" valign="middle" style="padding:0 10px 0 0;"><img class="pl-foot-logo" src="https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_144/c_pad,w_172,h_180,b_white/r_26/f_png/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png" alt="IKAIKA BIM" width="86" style="display:block;width:86px;max-width:86px;height:auto;border:0;" /></td>
          <td valign="middle" width="1" height="72" style="width:1px;height:72px;background-color:#4a74a1;font-size:0;line-height:0;">&nbsp;</td>
          <td class="pl-foot-text" valign="middle" style="padding:0 0 0 17px;">
            <div style="font-size:19px;line-height:22px;font-weight:700;letter-spacing:0.25px;color:#174d87;">{name}</div>
            <div style="margin-top:4px;font-size:12px;line-height:16px;color:#245b99;">{title}&nbsp;&middot;&nbsp;{department}</div>
            <div style="margin-top:8px;font-size:11px;line-height:15px;color:#245b99;white-space:nowrap;"><a href="mailto:{email}" style="color:#245b99;text-decoration:none;">{email}</a>&nbsp;&middot;&nbsp;{phone}&nbsp;&middot;&nbsp;{location}</div>
          </td>
        </tr>
      </table>
    </td>
    <td class="pl-foot-col" valign="middle" width="230" style="width:230px;padding:6px 0 18px 0;">
      <table role="presentation" class="pl-foot-address" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;border-left:1px solid #b7cbe4;">
        <tr>
          <td style="padding:5px 0 5px 22px;">
            <div style="font-size:15px;line-height:19px;font-weight:700;letter-spacing:0.2px;color:#174d87;">IKAIKA BIM</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;border-collapse:collapse;">
              <tr>
                <td valign="middle" style="padding:0 7px 4px 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791871370/email-design/icon-globe.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="padding:0 0 4px;font-size:11px;line-height:15px;white-space:nowrap;"><a href="https://ikaikabim.com" style="color:#245b99;text-decoration:none;">ikaikabim.com</a></td>
              </tr>
              <tr>
                <td valign="middle" style="padding:0 7px 0 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791871371/email-design/icon-mail.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="font-size:11px;line-height:15px;white-space:nowrap;"><a href="mailto:info@ikaikabim.com" style="color:#245b99;text-decoration:none;">info@ikaikabim.com</a></td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td colspan="2" style="padding:11px 0 16px;border-top:1px solid #d5e0ee;font-size:10px;line-height:16px;color:#7f99bb;">
      This message may contain confidential information and is meant only for the people it is addressed to. If you received it by mistake, please let the sender know and delete it.
    </td>
  </tr>
</table>
HTML;

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    private function swap(string $from, string $to): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('email_blocks')) {
            return;
        }

        $portal = DB::connection('portal');
        foreach ($portal->table('email_blocks')->where('kind', 'footer')->get(['id', 'body']) as $block) {
            // A body saved from Windows keeps CRLF; it is compared either way.
            if (rtrim(str_replace("\r\n", "\n", (string) $block->body)) === $from) {
                $portal->table('email_blocks')->where('id', $block->id)->update(['body' => $to]);
            }
        }
    }

    private function portalReachable(): bool
    {
        try {
            Schema::connection('portal')->getConnection()->getPdo();
        } catch (Throwable) {
            return false;
        }

        return true;
    }
};
