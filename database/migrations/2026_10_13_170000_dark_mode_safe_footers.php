<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The built-in footers made safe for dark mode: mail clients darken the page but not pictures, so the
 * logo now sits on a white tile (unseen on white, a badge on dark) and the website and mailbox icons
 * carry a thin white outline. database/seeders/email and sql/portal/separate.sql carry the new
 * versions; this swaps each in for a footer that is still exactly the previous built-in, so a footer
 * someone rewrote is left as it is.
 */
return new class extends Migration
{
    public const COMPANY_OLD = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <tr>
    <!-- Brand: logo, divider, IKAIKA BIM over IKAIKA PORTAL, then the website and the mailbox -->
    <td class="pl-foot-col" valign="middle" style="padding:6px 26px 18px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <td class="pl-foot-logo-cell" valign="middle" style="padding:0 17px 0 0;">
            <img class="pl-foot-logo" src="https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_144,f_auto,q_auto/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png" alt="IKAIKA BIM" width="72" style="display:block;width:72px;max-width:72px;height:auto;border:0;outline:none;text-decoration:none;" />
          </td>
          <td valign="middle" width="1" height="72" style="width:1px;height:72px;background-color:#4a74a1;font-size:0;line-height:0;">&nbsp;</td>
          <td class="pl-foot-text" valign="middle" style="padding:0 0 0 17px;">
            <div style="font-size:17px;line-height:20px;font-weight:700;letter-spacing:0.25px;color:#174d87;">IKAIKA BIM</div>
            <div style="margin-top:5px;font-size:10px;line-height:12px;font-weight:700;letter-spacing:2.4px;color:#658bbd;">IKAIKA PORTAL</div>
            <div style="margin-top:4px;font-size:11px;line-height:15px;letter-spacing:0.15px;color:#245b99;">Automated communication</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;border-collapse:collapse;">
              <tr>
                <td valign="middle" style="padding:0 7px 0 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791865759/email-design/icon-globe.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="font-size:11px;line-height:15px;white-space:nowrap;"><a href="https://ikaikabim.com" style="color:#245b99;text-decoration:none;">ikaikabim.com</a></td>
                <td valign="middle" style="padding:0 12px;"><div style="width:1px;height:17px;background-color:#bfd0e5;font-size:0;line-height:0;">&nbsp;</div></td>
                <td valign="middle" style="padding:0 7px 0 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791865760/email-design/icon-mail.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="font-size:11px;line-height:15px;white-space:nowrap;"><a href="mailto:info@ikaikabim.com" style="color:#245b99;text-decoration:none;">info@ikaikabim.com</a></td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
    <!-- Office: the pin and the address, a link of its own so a mail client does not underline it -->
    <td class="pl-foot-col" valign="middle" width="250" style="width:250px;padding:6px 0 18px 0;">
      <table role="presentation" class="pl-foot-address" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;border-left:1px solid #b7cbe4;">
        <tr>
          <td valign="top" style="padding:5px 12px 5px 22px;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791865761/email-design/icon-pin.png" alt="" width="19" height="23" style="display:block;width:19px;height:23px;border:0;" /></td>
          <td valign="top" style="padding:5px 0;font-size:11px;line-height:19px;color:#6682a8;white-space:nowrap;">
            <a href="https://www.google.com/maps/search/?api=1&amp;query=SM+Aura+Office+Tower+Taguig" style="color:#6682a8;text-decoration:none;">11th Floor, SM Aura Office Tower<br />26th St. corner McKinley Parkway<br />Fort Bonifacio, Taguig City,<br />Metro Manila, Philippines</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <!-- Confidentiality notice -->
  <tr>
    <td colspan="2" style="padding:11px 0 16px;border-top:1px solid #d5e0ee;font-size:10px;line-height:16px;color:#7f99bb;">
      This message may contain confidential information and is meant only for the people it is addressed to.<br />
      If you received it by mistake, please let the sender know and delete it.
    </td>
  </tr>
</table>
HTML;

    public const COMPANY_NEW = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <tr>
    <!-- Brand: logo, divider, IKAIKA BIM over IKAIKA PORTAL, then the website and the mailbox -->
    <td class="pl-foot-col" valign="middle" style="padding:6px 26px 18px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <!-- The logo sits on a white tile: unseen on white, a badge where a mail client turns the page dark -->
          <td class="pl-foot-logo-cell" valign="middle" style="padding:0 10px 0 0;">
            <img class="pl-foot-logo" src="https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_144/c_pad,w_172,h_180,b_white/r_26/f_png/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png" alt="IKAIKA BIM" width="86" style="display:block;width:86px;max-width:86px;height:auto;border:0;outline:none;text-decoration:none;" />
          </td>
          <td valign="middle" width="1" height="72" style="width:1px;height:72px;background-color:#4a74a1;font-size:0;line-height:0;">&nbsp;</td>
          <td class="pl-foot-text" valign="middle" style="padding:0 0 0 17px;">
            <div style="font-size:17px;line-height:20px;font-weight:700;letter-spacing:0.25px;color:#174d87;">IKAIKA BIM</div>
            <div style="margin-top:5px;font-size:10px;line-height:12px;font-weight:700;letter-spacing:2.4px;color:#658bbd;">IKAIKA PORTAL</div>
            <div style="margin-top:4px;font-size:11px;line-height:15px;letter-spacing:0.15px;color:#245b99;">Automated communication</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;border-collapse:collapse;">
              <tr>
                <td valign="middle" style="padding:0 7px 0 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791871370/email-design/icon-globe.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="font-size:11px;line-height:15px;white-space:nowrap;"><a href="https://ikaikabim.com" style="color:#245b99;text-decoration:none;">ikaikabim.com</a></td>
                <td valign="middle" style="padding:0 12px;"><div style="width:1px;height:17px;background-color:#bfd0e5;font-size:0;line-height:0;">&nbsp;</div></td>
                <td valign="middle" style="padding:0 7px 0 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791871371/email-design/icon-mail.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="font-size:11px;line-height:15px;white-space:nowrap;"><a href="mailto:info@ikaikabim.com" style="color:#245b99;text-decoration:none;">info@ikaikabim.com</a></td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
    <!-- Office: the pin and the address, a link of its own so a mail client does not underline it -->
    <td class="pl-foot-col" valign="middle" width="250" style="width:250px;padding:6px 0 18px 0;">
      <table role="presentation" class="pl-foot-address" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;border-left:1px solid #b7cbe4;">
        <tr>
          <td valign="top" style="padding:5px 12px 5px 22px;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791865761/email-design/icon-pin.png" alt="" width="19" height="23" style="display:block;width:19px;height:23px;border:0;" /></td>
          <td valign="top" style="padding:5px 0;font-size:11px;line-height:19px;color:#6682a8;white-space:nowrap;">
            <a href="https://www.google.com/maps/search/?api=1&amp;query=SM+Aura+Office+Tower+Taguig" style="color:#6682a8;text-decoration:none;">11th Floor, SM Aura Office Tower<br />26th St. corner McKinley Parkway<br />Fort Bonifacio, Taguig City,<br />Metro Manila, Philippines</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <!-- Confidentiality notice -->
  <tr>
    <td colspan="2" style="padding:11px 0 16px;border-top:1px solid #d5e0ee;font-size:10px;line-height:16px;color:#7f99bb;">
      This message may contain confidential information and is meant only for the people it is addressed to.<br />
      If you received it by mistake, please let the sender know and delete it.
    </td>
  </tr>
</table>
HTML;

    public const BIM_OLD = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <tr>
    <!-- Brand: logo, divider, IKAIKA BIM, then the website and the mailbox; the company itself, not the portal -->
    <td class="pl-foot-col" valign="middle" style="padding:6px 26px 18px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <td class="pl-foot-logo-cell" valign="middle" style="padding:0 17px 0 0;">
            <img class="pl-foot-logo" src="https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_144,f_auto,q_auto/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png" alt="IKAIKA BIM" width="72" style="display:block;width:72px;max-width:72px;height:auto;border:0;outline:none;text-decoration:none;" />
          </td>
          <td valign="middle" width="1" height="72" style="width:1px;height:72px;background-color:#4a74a1;font-size:0;line-height:0;">&nbsp;</td>
          <td class="pl-foot-text" valign="middle" style="padding:0 0 0 17px;">
            <div style="font-size:19px;line-height:22px;font-weight:700;letter-spacing:0.25px;color:#174d87;">IKAIKA BIM</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;border-collapse:collapse;">
              <tr>
                <td valign="middle" style="padding:0 7px 0 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791865759/email-design/icon-globe.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="font-size:11px;line-height:15px;white-space:nowrap;"><a href="https://ikaikabim.com" style="color:#245b99;text-decoration:none;">ikaikabim.com</a></td>
                <td valign="middle" style="padding:0 12px;"><div style="width:1px;height:17px;background-color:#bfd0e5;font-size:0;line-height:0;">&nbsp;</div></td>
                <td valign="middle" style="padding:0 7px 0 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791865760/email-design/icon-mail.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="font-size:11px;line-height:15px;white-space:nowrap;"><a href="mailto:info@ikaikabim.com" style="color:#245b99;text-decoration:none;">info@ikaikabim.com</a></td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
    <!-- Office: the pin and the address, a link of its own so a mail client does not underline it -->
    <td class="pl-foot-col" valign="middle" width="250" style="width:250px;padding:6px 0 18px 0;">
      <table role="presentation" class="pl-foot-address" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;border-left:1px solid #b7cbe4;">
        <tr>
          <td valign="top" style="padding:5px 12px 5px 22px;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791865761/email-design/icon-pin.png" alt="" width="19" height="23" style="display:block;width:19px;height:23px;border:0;" /></td>
          <td valign="top" style="padding:5px 0;font-size:11px;line-height:19px;color:#6682a8;white-space:nowrap;">
            <a href="https://www.google.com/maps/search/?api=1&amp;query=SM+Aura+Office+Tower+Taguig" style="color:#6682a8;text-decoration:none;">11th Floor, SM Aura Office Tower<br />26th St. corner McKinley Parkway<br />Fort Bonifacio, Taguig City,<br />Metro Manila, Philippines</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <!-- Confidentiality notice -->
  <tr>
    <td colspan="2" style="padding:11px 0 16px;border-top:1px solid #d5e0ee;font-size:10px;line-height:16px;color:#7f99bb;">
      This message may contain confidential information and is meant only for the people it is addressed to.<br />
      If you received it by mistake, please let the sender know and delete it.
    </td>
  </tr>
</table>
HTML;

    public const BIM_NEW = <<<'HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <tr>
    <!-- Brand: logo, divider, IKAIKA BIM, then the website and the mailbox; the company itself, not the portal -->
    <td class="pl-foot-col" valign="middle" style="padding:6px 26px 18px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <!-- The logo sits on a white tile: unseen on white, a badge where a mail client turns the page dark -->
          <td class="pl-foot-logo-cell" valign="middle" style="padding:0 10px 0 0;">
            <img class="pl-foot-logo" src="https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_144/c_pad,w_172,h_180,b_white/r_26/f_png/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png" alt="IKAIKA BIM" width="86" style="display:block;width:86px;max-width:86px;height:auto;border:0;outline:none;text-decoration:none;" />
          </td>
          <td valign="middle" width="1" height="72" style="width:1px;height:72px;background-color:#4a74a1;font-size:0;line-height:0;">&nbsp;</td>
          <td class="pl-foot-text" valign="middle" style="padding:0 0 0 17px;">
            <div style="font-size:19px;line-height:22px;font-weight:700;letter-spacing:0.25px;color:#174d87;">IKAIKA BIM</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;border-collapse:collapse;">
              <tr>
                <td valign="middle" style="padding:0 7px 0 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791871370/email-design/icon-globe.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="font-size:11px;line-height:15px;white-space:nowrap;"><a href="https://ikaikabim.com" style="color:#245b99;text-decoration:none;">ikaikabim.com</a></td>
                <td valign="middle" style="padding:0 12px;"><div style="width:1px;height:17px;background-color:#bfd0e5;font-size:0;line-height:0;">&nbsp;</div></td>
                <td valign="middle" style="padding:0 7px 0 0;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791871371/email-design/icon-mail.png" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;" /></td>
                <td valign="middle" style="font-size:11px;line-height:15px;white-space:nowrap;"><a href="mailto:info@ikaikabim.com" style="color:#245b99;text-decoration:none;">info@ikaikabim.com</a></td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
    <!-- Office: the pin and the address, a link of its own so a mail client does not underline it -->
    <td class="pl-foot-col" valign="middle" width="250" style="width:250px;padding:6px 0 18px 0;">
      <table role="presentation" class="pl-foot-address" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;border-left:1px solid #b7cbe4;">
        <tr>
          <td valign="top" style="padding:5px 12px 5px 22px;"><img src="https://res.cloudinary.com/ezmjw0z8/image/upload/v1791865761/email-design/icon-pin.png" alt="" width="19" height="23" style="display:block;width:19px;height:23px;border:0;" /></td>
          <td valign="top" style="padding:5px 0;font-size:11px;line-height:19px;color:#6682a8;white-space:nowrap;">
            <a href="https://www.google.com/maps/search/?api=1&amp;query=SM+Aura+Office+Tower+Taguig" style="color:#6682a8;text-decoration:none;">11th Floor, SM Aura Office Tower<br />26th St. corner McKinley Parkway<br />Fort Bonifacio, Taguig City,<br />Metro Manila, Philippines</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <!-- Confidentiality notice -->
  <tr>
    <td colspan="2" style="padding:11px 0 16px;border-top:1px solid #d5e0ee;font-size:10px;line-height:16px;color:#7f99bb;">
      This message may contain confidential information and is meant only for the people it is addressed to.<br />
      If you received it by mistake, please let the sender know and delete it.
    </td>
  </tr>
</table>
HTML;

    public function up(): void
    {
        $this->swap([self::COMPANY_OLD => self::COMPANY_NEW, self::BIM_OLD => self::BIM_NEW]);
    }

    public function down(): void
    {
        $this->swap([self::COMPANY_NEW => self::COMPANY_OLD, self::BIM_NEW => self::BIM_OLD]);
    }

    /**
     * @param  array<string, string>  $versions  previous body => next body
     */
    private function swap(array $versions): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('email_blocks')) {
            return;
        }

        $portal = DB::connection('portal');
        foreach ($portal->table('email_blocks')->where('kind', 'footer')->get(['id', 'body']) as $block) {
            // A body saved from Windows keeps CRLF; it is compared either way.
            $body = rtrim(str_replace("\r\n", "\n", (string) $block->body));
            if (isset($versions[$body])) {
                $portal->table('email_blocks')->where('id', $block->id)->update(['body' => $versions[$body]]);
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
