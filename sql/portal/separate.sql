USE test_portal_database;

-- ============================================================================
-- PORTAL AUDIT — user tracking and in-app notifications
-- logs: every member action the portal backend records (no tokens / secrets).
-- as_developer: written while the actor was in Developer mode; All Logs names them as
-- "A developer" to anyone not in Developer mode, and with the mode to anyone who is.
-- notifications: inbox rows. link_path is the screen the UI opens on click;
-- payload holds ids and other extras for that screen. read_at is null until read.
-- ============================================================================

CREATE TABLE logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT          NOT NULL,
    action        VARCHAR(64)  NOT NULL,
    resource      VARCHAR(191) NOT NULL,
    record_id     VARCHAR(191) NULL,
    message       TEXT         NULL,
    ip_address    VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    location_label  VARCHAR(255) NULL,
    location_source VARCHAR(32) NULL,
    location_lat    DECIMAL(10,7) NULL,
    location_lng    DECIMAL(10,7) NULL,
    as_developer  TINYINT(1)   NOT NULL DEFAULT 0,
    payload       JSON         NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_employee_created (employee_id, created_at),
    INDEX idx_logs_resource_created (resource, created_at),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT          NOT NULL,
    actor_id      INT          NULL,
    type          VARCHAR(64)  NOT NULL,
    title         VARCHAR(255) NOT NULL,
    message       TEXT         NOT NULL,
    link_path     VARCHAR(500) NULL,
    link_label    VARCHAR(100) NULL,
    payload       JSON         NULL,
    read_at       TIMESTAMP    NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_inbox (employee_id, read_at, created_at),
    INDEX idx_notifications_employee_created (employee_id, created_at),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id)    REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- EVENT CALENDAR — member-created company and project events
-- audience everyone is visible to every signed-in member. department rows
-- match employees.department. members rows name specific employee ids.
-- The creator always sees their own event. Existing databases: the Laravel
-- migration create_portal_calendar_events_tables.
-- ============================================================================

CREATE TABLE calendar_events (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    details      TEXT         NULL,
    starts_on    DATE         NOT NULL,
    starts_at    TIME         NULL,
    ends_on      DATE         NULL,
    ends_at      TIME         NULL,
    category     VARCHAR(32)  NOT NULL,
    audience     VARCHAR(32)  NOT NULL,
    created_by   INT          NOT NULL,
    date_created DATETIME     NULL,
    INDEX idx_calendar_events_starts_on (starts_on),
    INDEX idx_calendar_events_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE calendar_event_departments (
    event_id   INT          NOT NULL,
    department VARCHAR(100) NOT NULL,
    PRIMARY KEY (event_id, department),
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE calendar_event_members (
    event_id    INT NOT NULL,
    employee_id INT NOT NULL,
    PRIMARY KEY (event_id, employee_id),
    INDEX idx_calendar_event_members_employee (employee_id),
    FOREIGN KEY (event_id)    REFERENCES calendar_events(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- REPORT TEMPLATES -- the report forms' Use a Template panel
-- report_templates: one person's saved recipe for a report day, per user: the
-- builder's rows as labels (a project, its activities with hours) plus the
-- remarks, so it refills the picker as text. body is JSON:
-- {projects: [{projectLabel, activities: [{activityLabel, hours}]}]}.
-- Only its owner reads, changes or removes a row.
-- Existing databases: create_portal_report_templates.
-- ============================================================================

CREATE TABLE IF NOT EXISTS report_templates (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT          NOT NULL,
    name         VARCHAR(100) NOT NULL,
    notes        TEXT         NULL,
    body         JSON         NOT NULL,
    date_created DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated DATETIME     NULL,
    INDEX idx_report_templates_employee (employee_id, name),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- ADMIN EMAIL — the Send Email page
-- email_blocks: what the composer reuses. kind 'template' carries a category and
-- subject; kind 'footer' is a signature or disclaimer appended under the body.
-- email_messages: the outbox, one row per send, holding a snapshot of the body
-- and footer it went out with, so editing a block never rewrites history.
-- audience_filter holds departments, roles and employee ids for that audience.
-- format is 'markup' (the toolbar's light markup, escaped) or 'html' (sent as written);
-- footer_format is the snapshot footer's own format. A custom audience keeps its typed
-- addresses in audience_filter.emails; cc and bcc are the addresses that shared one copy.
-- from_name and reply_to are set when an administrator sent it as themselves.
-- recipients is who the send went to, [{name, email}]; null on sends from before it was kept.
-- email_attachments: a file on the backend's private disk (storage/app/private), staged while
-- message_id is null and the outbox row's once a send claims it. token is the id the browser
-- holds; name and mime are what it was mailed as.
-- Existing databases: the Laravel migrations create_portal_email_tables and
-- add_format_to_portal_email_tables, add_copies_to_portal_email_messages,
-- add_sender_to_portal_email_messages, seed_portal_email_built_in_blocks,
-- add_recipients_to_portal_email_messages and create_portal_email_attachments_table.
-- ============================================================================

CREATE TABLE email_blocks (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    kind         VARCHAR(16)  NOT NULL,
    name         VARCHAR(191) NOT NULL,
    category     VARCHAR(32)  NULL,
    subject      VARCHAR(255) NULL,
    body         TEXT         NOT NULL,
    format       VARCHAR(8)   NOT NULL DEFAULT 'markup',
    created_by   INT          NOT NULL,
    date_created DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated DATETIME     NULL,
    INDEX idx_email_blocks_kind (kind, name),
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    category        VARCHAR(32)  NOT NULL,
    subject         VARCHAR(255) NOT NULL,
    body            TEXT         NOT NULL,
    format          VARCHAR(8)   NOT NULL DEFAULT 'markup',
    footer_id       INT          NULL,
    footer_body     TEXT         NULL,
    footer_format   VARCHAR(8)   NULL,
    cc              JSON         NULL,
    bcc             JSON         NULL,
    from_name       VARCHAR(191) NULL,
    reply_to        VARCHAR(191) NULL,
    -- "Send as me": the sender's own address, when they chose it, so the From is theirs (a reply and
    -- the visible sender both the person). Null leaves from the portal's own noreply.
    -- Existing databases: add_portal_email_from_address.
    from_address    VARCHAR(191) NULL,
    audience        VARCHAR(32)  NOT NULL,
    audience_filter JSON         NULL,
    recipients      JSON         NULL,
    recipient_count INT          NOT NULL DEFAULT 0,
    sent_count      INT          NOT NULL DEFAULT 0,
    failed_count    INT          NOT NULL DEFAULT 0,
    failures        JSON         NULL,
    status          VARCHAR(16)  NOT NULL DEFAULT 'sent',
    -- Null for a guest's send: the Email page is shareable, and a guest is no employee.
    -- Existing databases: allow_guest_email_sends.
    sent_by         INT          NULL,
    date_sent       DATETIME     NULL,
    date_created    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_messages_created (date_created),
    INDEX idx_email_messages_sent_by (sent_by),
    FOREIGN KEY (footer_id) REFERENCES email_blocks(id) ON DELETE SET NULL,
    FOREIGN KEY (sent_by)   REFERENCES employees(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_attachments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    token        CHAR(36)     NOT NULL,
    message_id   INT          NULL,
    name         VARCHAR(191) NOT NULL,
    mime         VARCHAR(127) NOT NULL,
    size_bytes   INT          NOT NULL,
    path         VARCHAR(255) NOT NULL,
    uploaded_by  INT          NOT NULL,
    date_created DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_attachments_token (token),
    INDEX idx_email_attachments_message (message_id),
    INDEX idx_email_attachments_staged (uploaded_by, message_id),
    FOREIGN KEY (message_id)  REFERENCES email_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES employees(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Built-in blocks: the company footer and the Portal email test template. The HTML is
-- database/seeders/email/*.html, inlined here; its logo is in Cloudinary's global folder.
INSERT INTO email_blocks (kind, name, category, subject, body, format, created_by)
SELECT 'footer', 'Company footer', NULL, NULL, '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Inter,''Segoe UI'',Roboto,Helvetica,Arial,sans-serif;">
  <tr>
    <!-- Brand: logo, divider, IKAIKA BIM over IKAIKA PORTAL, then the website and the mailbox -->
    <td class="pl-foot-col" valign="middle" style="padding:6px 26px 18px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <!-- The logo sits on a white tile: unseen on white, a badge where a mail client turns the page dark -->
          <td class="pl-foot-logo-cell" valign="middle" style="padding:0 10px 0 0;">
            <img class="pl-foot-logo" src="https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_136/c_lpad,w_176,h_184,b_white/r_28/f_png/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png" alt="IKAIKA BIM" width="88" style="display:block;width:88px;max-width:88px;height:auto;border:0;outline:none;text-decoration:none;" />
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
</table>', 'html', id
FROM employees WHERE id_no = '260701-0020'; -- John Dave Pega
-- A signature footer (database/seeders/email/company-signature.html): the $SIGNATURE token is replaced with the person's own details when it is used
-- as an email signature (Manage / Signatures, and each employee's Settings). The plain Company footer
-- above has no token, so it is usable in email but cannot be a signature.
INSERT INTO email_blocks (kind, name, category, subject, body, format, created_by)
SELECT 'footer', 'Company signature', NULL, NULL, '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Inter,''Segoe UI'',Roboto,Helvetica,Arial,sans-serif;">
  <tr>
    <td class="pl-foot-col" valign="middle" style="padding:6px 26px 18px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <td class="pl-foot-logo-cell" valign="middle" style="padding:0 10px 0 0;"><img class="pl-foot-logo" src="https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_136/c_lpad,w_176,h_184,b_white/r_28/f_png/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png" alt="IKAIKA BIM" width="88" style="display:block;width:88px;max-width:88px;height:auto;border:0;" /></td>
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
</table>', 'html', id
FROM employees WHERE id_no = '260701-0020'; -- John Dave Pega
INSERT INTO email_blocks (kind, name, category, subject, body, format, created_by)
SELECT 'template', 'Portal email test', 'notice', 'Portal email test', '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;border-collapse:collapse;font-family:Inter,''Segoe UI'',Roboto,Helvetica,Arial,sans-serif;">
  <tr>
    <td style="padding:0 0 8px 0;font-size:22px;line-height:30px;font-weight:700;color:#111827;">
      Portal email test
    </td>
  </tr>
  <tr>
    <td style="padding:0 0 16px 0;font-size:15px;line-height:24px;color:#374151;">
      Hi team,<br><br>
      This is a test of the new IKAIKA Portal email.
    </td>
  </tr>
  <tr>
    <td style="padding:4px 0 24px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">
        <tr>
          <td bgcolor="#2563eb" style="border-radius:8px;">
            <a href="https://ikaikabim.com" target="_blank" style="display:inline-block;padding:12px 22px;font-family:Inter,''Segoe UI'',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
              Visit ikaikabim.com
            </a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td style="font-size:15px;line-height:24px;color:#374151;">
      Thank you,<br>
      <strong style="font-weight:600;color:#111827;">IKAIKA BIM</strong>
    </td>
  </tr>
</table>', 'html', id
FROM employees WHERE id_no = '260701-0020'; -- John Dave Pega

-- The IKAIKA BIM footer: the company writing as itself (From: IKAIKA BIM), without the portal's lines.
INSERT INTO email_blocks (kind, name, category, subject, body, format, created_by)
SELECT 'footer', 'IKAIKA BIM footer', NULL, NULL, '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Inter,''Segoe UI'',Roboto,Helvetica,Arial,sans-serif;">
  <tr>
    <!-- Brand: logo, divider, IKAIKA BIM, then the website and the mailbox; the company itself, not the portal -->
    <td class="pl-foot-col" valign="middle" style="padding:6px 26px 18px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
          <!-- The logo sits on a white tile: unseen on white, a badge where a mail client turns the page dark -->
          <td class="pl-foot-logo-cell" valign="middle" style="padding:0 10px 0 0;">
            <img class="pl-foot-logo" src="https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_136/c_lpad,w_176,h_184,b_white/r_28/f_png/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png" alt="IKAIKA BIM" width="88" style="display:block;width:88px;max-width:88px;height:auto;border:0;outline:none;text-decoration:none;" />
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
</table>', 'html', id
FROM employees WHERE id_no = '260701-0020'; -- John Dave Pega


-- ============================================================================
-- REPORT FLAGGING — is_flag / flag_status / flag_remarks on employees_user_reports
-- A daily or late report filed by an employee who currently has an Active row in
-- `warnings` is still recorded exactly like anyone else's -- only this junction row
-- is marked for a reviewer's attention. is_flag is set once at submission time
-- (PortalSubmittedReports::insertLine) and never touched again: it is the permanent
-- fact that this report was filed while the employee was on a warning. flag_status
-- and flag_remarks are the reviewable part -- an approver's decision and note,
-- written together (PortalManageRequests::applyFlaggedReport), same as every other
-- request kind's status + approverRemarks pair.
--
-- Plain ADD COLUMN, not IF NOT EXISTS: MySQL never supported that clause on ADD
-- COLUMN (unlike CREATE TABLE/DATABASE), and reset.ps1 always runs this against a
-- table schema.sql just dropped and recreated, so there is nothing to retrofit.
-- ============================================================================

ALTER TABLE employees_user_reports
    ADD COLUMN is_flag      TINYINT(1)  NOT NULL DEFAULT 0,
    ADD COLUMN flag_status  VARCHAR(50) NOT NULL DEFAULT 'Pending',
    ADD COLUMN flag_remarks TEXT        NULL;


-- ============================================================================
-- DEVELOPER ACCESS — is_developer on employees
-- Portal-only, like the flags above: Airtable's Employees table has no such field and
-- the sync never sends it. A developer will sit above an admin (who has limits a
-- developer will not); nothing reads it yet. Read-only in the admin grid
-- (config/workspace.php readonly_columns), so an admin cannot grant it to themselves.
-- ============================================================================

ALTER TABLE employees
    ADD COLUMN is_developer TINYINT(1) NOT NULL DEFAULT 0;

-- The portal's developers. Matched on id_no, never on name: another employee carries
-- "Malabanan" as a middle name. Needs the employees data.sql loads, which is why
-- reset.ps1 runs this file after data.sql.
UPDATE employees
SET is_developer = 1
WHERE id_no IN (
    '260701-0020', -- John Dave Pega
    '260701-0019', -- Jhon Noriel Malabanan
    '260701-0018', -- Jolas Mendez
    '250407-0009', -- Jolas Mendez
    '220101-0001', -- Don De Guzman, the president
    '220202-0002'  -- Nathaniel Tanglao, the MIS manager
);

-- Developer mode: is_developer alone grants nothing any more. A developer uses the portal
-- as their plain role until they switch Developer mode on in Settings with their own
-- password, and it lasts until they sign out (active_session is the signed-in token's
-- sid). No row yet, or a NULL password_hash, means the default password 123 still
-- stands and must be replaced on first use. Kept off employees so the hash never reaches
-- the admin grid or the Airtable sync.
CREATE TABLE IF NOT EXISTS developer_modes (
    employee_id    INT          NOT NULL PRIMARY KEY,
    password_hash  VARCHAR(255) NULL,
    active_session VARCHAR(64)  NULL,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);



-- ============================================================================
-- PROJECT SCOPES — the WBS codes picked in View Projects' Manage Scopes
-- One row per project and chosen code; wbs_code is a code from project_scope_t1_processes
-- .. t4_tasks (e.g. '500-04-03'). The Airtable-shaped pivots (projects_project_scopes,
-- projects_activity_scope) stay as they are; View Projects reads this table first.
-- Portal-only: never synced. Existing databases: the Laravel migration
-- create_portal_projects_wbs_scopes.
-- ============================================================================

-- Manage / Projects: who asked for a project added in the portal, and when. Null on Airtable's own rows.
-- A project added without Project Manager covering its department waits (project_creation_status
-- 'Pending') until one approves or rejects it. Existing databases: add_portal_project_manager_rule.
ALTER TABLE projects
    ADD COLUMN requested_by INT       NULL,
    ADD COLUMN requested_at TIMESTAMP NULL;

CREATE TABLE IF NOT EXISTS projects_wbs_scopes (
    project_id INT         NOT NULL,
    wbs_code   VARCHAR(32) NOT NULL,
    PRIMARY KEY (project_id, wbs_code),
    CONSTRAINT fk_projects_wbs_scopes_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
);

-- The activities a project takes out of its Type of project's allowed list (job_type_allowed_activities).
-- A project only removes, never adds; no rows means the type's whole list. Existing databases:
-- create_portal_projects_removed_activities.
CREATE TABLE IF NOT EXISTS projects_removed_activities (
    project_id    INT         NOT NULL,
    activity_code VARCHAR(32) NOT NULL,
    PRIMARY KEY (project_id, activity_code),
    CONSTRAINT fk_projects_removed_activities_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
);



-- ============================================================================
-- BITBUCKET — an MIS project's code activity (docs/power/view-projects-dashboard-plan.md, step 7)
-- bitbucket_repositories: one row per repository, holding its sync state, so a repository linked
-- to two projects is read once. projects_bitbucket_repositories: which projects a repository
-- belongs to (many per project, and a repository may serve several). bitbucket_commits: one row
-- per commit per repository, whichever branches carry it; author_email is matched to
-- employees.email (employee_id NULL = "Unknown author", never dropped); is_merge rows are kept but
-- never counted, so lines stay with whoever wrote them. Only linked repositories are ever synced.
-- committed_at is UTC; committed_on is that moment's calendar day in company time (Asia/Manila), the
-- day a Daily or Late Report for that date reads, so work counts on the day it was written, not
-- pushed. counted_at NULL means its line counts are not fetched yet (a big history fills over several
-- runs); history_complete says one listing reached the first commit, so later runs may stop early.
-- message is internal (lead, managers, Admin+ only). Portal-only: never synced.
-- Existing databases: the Laravel migrations create_portal_bitbucket_tables, add_portal_bitbucket_commit_day.
-- ============================================================================

CREATE TABLE IF NOT EXISTS bitbucket_repositories (
    id              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    workspace       VARCHAR(64)  NOT NULL,
    repo_slug       VARCHAR(128) NOT NULL,
    last_synced_at  TIMESTAMP    NULL,
    last_sync_error VARCHAR(255) NULL,
    history_complete TINYINT(1)  NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bitbucket_repositories (workspace, repo_slug)
);

CREATE TABLE IF NOT EXISTS projects_bitbucket_repositories (
    project_id    INT NOT NULL,
    repository_id INT NOT NULL,
    PRIMARY KEY (project_id, repository_id),
    CONSTRAINT fk_projects_bitbucket_repositories_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_projects_bitbucket_repositories_repository FOREIGN KEY (repository_id) REFERENCES bitbucket_repositories (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS bitbucket_commits (
    repository_id INT              NOT NULL,
    hash          CHAR(40)         NOT NULL,
    author_name   VARCHAR(255)     NULL,
    author_email  VARCHAR(255)     NULL,
    employee_id   INT              NULL,
    committed_at  DATETIME         NOT NULL,
    committed_on  DATE             NOT NULL,
    message       TEXT             NULL,
    lines_added   INT UNSIGNED     NOT NULL DEFAULT 0,
    lines_removed INT UNSIGNED     NOT NULL DEFAULT 0,
    files_changed INT UNSIGNED     NOT NULL DEFAULT 0,
    is_merge      TINYINT(1)       NOT NULL DEFAULT 0,
    counted_at    TIMESTAMP        NULL,
    PRIMARY KEY (repository_id, hash),
    KEY ix_bitbucket_commits_repository_day (repository_id, committed_at),
    KEY ix_bitbucket_commits_employee_day (employee_id, committed_on),
    CONSTRAINT fk_bitbucket_commits_repository FOREIGN KEY (repository_id) REFERENCES bitbucket_repositories (id) ON DELETE CASCADE,
    CONSTRAINT fk_bitbucket_commits_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE SET NULL
);



-- ============================================================================
-- FINE-GRAINED RULES — the Access page (FINE_GRAINED_RULES.md)
-- access_permissions: the rule catalog, one row per page, tab or action. parent_key
-- builds the tree; supports_reach marks a rule that shows other people's records
-- (own / department / all); min_tier is the lowest role that may hold it at all (NULL
-- means anyone), so a member is never given it; 'developer' means only a developer in Developer
-- mode, which no gift can reach; only_department limits an admin to holding it only while in that
-- department (executives and Developer mode always may); can_toggle 0 keeps a rule its holder may never switch
-- off (the Access page itself, or nobody could switch anything back on). A description
-- never says "you" doing something: {UserName|Your} reads "Your" on the Access page and
-- "Jolas Mendez's" on someone's Access Rule sheet, so each sentence fits both.
-- access_presets: what each role starts with. A developer (employees.is_developer)
-- takes the developer row, then executive (role_level), then admin (role), then
-- member; ProjectAdmin is a plain member. include_executives lets that rule show
-- executives' rows, which only developers and executives see, on All Logs only.
-- access_toggles: a person's own switched-off rules; no row means on. A toggle is
-- their convenience only: it never adds a power and never touches what they gave.
-- access_self_reach: how far a person narrowed a reach rule for themselves; no row means
-- as far as they hold it. It only ever narrows, and never touches what they gave.
-- access_grants: a power one person gave another. It counts only while the giver
-- may still manage them and still holds that power, and never wider than the
-- giver's own reach, so taking a power from someone silently ends everything they
-- gave from it (PortalAccessGraph).
-- access_limits: a power taken from someone, with everything under it, whatever
-- gave it to them. Counts under the same conditions as a grant.
-- access_personal: what one person starts with on top of their role (their own defaults), seeded
-- below by id_no like is_developer. Counts like their role preset: a limit still takes it away.
-- access_lists: a white list (allow: only these people) or black list (block: never these
-- people) one maker set on someone's rule, for a rule marked supports_list. Counts under the
-- same conditions as a limit; every black list hides and every white list must name a person.
-- A maker lists only people they can see themselves, and keeps one kind of list per rule. The
-- rule's ignore power (<key>.unlisted) skips every list, as Developer mode does.
-- as_developer on a grant, limit or list: made in Developer mode. Only those count as a
-- developer's; one made in Default is judged as the maker's role like anyone's, and a maker in
-- Default can see but never change what they made in Developer mode.
-- Portal-only: no airtable_record_id, never synced, read-only in the admin grid.
-- Pages switch over to their rules one at a time (FINE_GRAINED_RULES.md, Enforced so far).
-- ============================================================================

CREATE TABLE access_permissions (
    permission_key VARCHAR(191) PRIMARY KEY,
    parent_key     VARCHAR(191) NULL,
    label          VARCHAR(191) NOT NULL,
    description    TEXT         NOT NULL,
    supports_reach TINYINT(1)   NOT NULL DEFAULT 0,
    supports_list  TINYINT(1)   NOT NULL DEFAULT 0,
    can_toggle     TINYINT(1)   NOT NULL DEFAULT 1,
    min_tier       VARCHAR(16)  NULL,
    only_department VARCHAR(100) NULL,
    sort_order     INT          NOT NULL DEFAULT 0,
    INDEX idx_access_permissions_parent (parent_key, sort_order),
    FOREIGN KEY (parent_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_presets (
    role               VARCHAR(16)  NOT NULL,
    permission_key     VARCHAR(191) NOT NULL,
    reach              VARCHAR(16)  NULL,
    include_executives TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (role, permission_key),
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_toggles (
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id, permission_key),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_self_reach (
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    reach          VARCHAR(16)  NOT NULL,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id, permission_key),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_grants (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    employee_id        INT          NOT NULL,
    permission_key     VARCHAR(191) NOT NULL,
    reach              VARCHAR(16)  NULL,
    include_executives TINYINT(1)   NOT NULL DEFAULT 0,
    granted_by         INT          NOT NULL,
    as_developer       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_grants (employee_id, permission_key, granted_by, as_developer),
    INDEX idx_access_grants_granted_by (granted_by),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (granted_by)     REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_limits (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    limited_by     INT          NOT NULL,
    as_developer   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_limits (employee_id, permission_key, limited_by, as_developer),
    INDEX idx_access_limits_limited_by (limited_by),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (limited_by)     REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_personal (
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    reach          VARCHAR(16)  NULL,
    PRIMARY KEY (employee_id, permission_key),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE access_lists (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT          NOT NULL,
    permission_key VARCHAR(191) NOT NULL,
    mode           VARCHAR(8)   NOT NULL,
    subject_id     INT          NOT NULL,
    set_by         INT          NOT NULL,
    as_developer   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_lists (employee_id, permission_key, set_by, as_developer, subject_id),
    INDEX idx_access_lists_set_by (set_by),
    FOREIGN KEY (employee_id)    REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (subject_id)     REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (set_by)         REFERENCES employees(id)                      ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES access_permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parents before children: parent_key must already exist when its child is inserted.
INSERT INTO access_permissions (permission_key, parent_key, label, description, supports_reach, can_toggle, sort_order) VALUES
('home', NULL, 'Home', 'Open the Home dashboard: {UserName|Your} hours, recent reports and followed projects.', 0, 1, 100),

('projects', NULL, 'Projects', 'Open the Projects section of the sidebar.', 0, 1, 200),
('projects.view', 'projects', 'View Projects', 'Browse projects and open each one''s dashboard, details and trail history.', 1, 1, 210),
('projects.edit', 'projects.view', 'Edit projects', 'Edit the details of {UserName|Your} department''s projects or every project. On Only your own, the projects {UserName|You} lead.', 1, 1, 211),
('projects.scopes', 'projects.view', 'Manage scopes', 'Choose the WBS scopes of {UserName|Your} department''s projects or every project. On Only your own, the projects {UserName|You} lead.', 1, 1, 212),
('projects.delete', 'projects.view', 'Delete projects', 'Move {UserName|Your} department''s projects or any project to the recycle bin. On Only your own, the projects {UserName|You} lead.', 1, 1, 213),
('projects.edit.closed', 'projects.edit', 'Edit closed projects', 'Change a Completed or Closed project, approved or not: {UserName|Your} department''s or every one. Nobody else can.', 1, 1, 215),
('projects.lead', 'projects.view', 'Project Lead', 'Be chosen as the lead of {UserName|Your} department''s projects or of any project.', 1, 1, 214),
-- Project Lead carries it, on and locked (2026-10-14, user): PortalAccessGraph::IMPLIED. Existing databases: add_portal_remove_scope_assignees_rule.
('projects.assignees.remove', 'projects.view', 'Remove scope assignees', 'Take someone assigned to a project''s scopes off its Members or Support members. Comes with Project Lead.', 0, 1, 216),
('projects.create', 'projects', 'Add projects', 'Start a new project from Add New Project, for {UserName|Your} department or every department.', 1, 1, 220),
('projects.create.direct', 'projects.create', 'Auto-approve projects', 'A project {UserName|You} add is approved at once, without waiting for a Project Approver.', 0, 1, 221),
-- Created Projects is its own power (2026-10-09, user): a person may be barred from adding projects
-- while still seeing the record of what they already submitted. No reach: it is only ever their own.
('projects.created', 'projects', 'Created Projects', 'See the projects {UserName|You} added and where their approval stands.', 0, 1, 225),
('projects.created.export', 'projects.created', 'Export', 'Download the projects {UserName|You} added as PDF, XLSX, Word, CSV or JSON.', 0, 1, 226),
('projects.track', 'projects', 'Track Projects', 'Follow projects by status, year, month and department: the projects {UserName|You} work on, plus {UserName|Your} department''s or every department''s.', 1, 1, 230),
('projects.track.export', 'projects.track', 'Export', 'Download the tracked projects as PDF, XLSX, Word, CSV or JSON.', 0, 1, 231),
('projects.references', 'projects', 'References', 'Open Manage / References.', 0, 1, 240),
('projects.references.edit', 'projects.references', 'Edit references', 'Add, change and remove the scopes, project types, departments and clients.', 0, 1, 241),

('reports', NULL, 'Reports', 'Open the Reports section of the sidebar.', 0, 1, 300),
('reports.daily', 'reports', 'Daily Report', 'File {UserName|Your} timesheet for today.', 0, 1, 310),
('reports.late', 'reports', 'Late Report', 'File {UserName|Your} timesheet for a missed past day.', 0, 1, 320),
('reports.submitted', 'reports', 'Submitted Reports', 'See {UserName|Your} filed reports on a calendar, with every entry.', 0, 1, 330),
('reports.submitted.edit', 'reports.submitted', 'Edit reports', 'Change one of {UserName|Your} reports filed in the last 7 days.', 0, 1, 331),
('reports.submitted.delete', 'reports.submitted', 'Delete reports', 'Delete one of {UserName|Your} reports filed in the last 7 days. It waits in {UserName|Your} recycle bin.', 0, 1, 332),
('reports.submitted.export', 'reports.submitted', 'Export', 'Download {UserName|Your} submitted reports.', 0, 1, 333),

('requests', NULL, 'Requests', 'Open the Requests section of the sidebar.', 0, 1, 400),
('requests.own', 'requests', 'User Requests', 'See {UserName|Your} leave, overtime, offset and reimbursement requests and flagged reports in one place.', 0, 1, 410),
('requests.own.edit', 'requests.own', 'Edit requests', 'Change one of {UserName|Your} requests while it is still pending.', 0, 1, 411),
('requests.own.cancel', 'requests.own', 'Cancel requests', 'Cancel one of {UserName|Your} requests while it is still pending.', 0, 1, 412),
('requests.own.export', 'requests.own', 'Export', 'Download {UserName|Your} requests.', 0, 1, 413),
('requests.leave', 'requests', 'Leave Request', 'File a leave request.', 0, 1, 420),
('requests.overtime', 'requests', 'Overtime Request', 'File an overtime request.', 0, 1, 430),
('requests.offset', 'requests', 'Offset Request', 'File an offset request.', 0, 1, 440),
('requests.reimbursement', 'requests', 'Reimbursement Request', 'File a reimbursement claim with its receipts.', 0, 1, 450),

('user', NULL, 'User', 'Open the User section of the sidebar.', 0, 1, 500),
('user.logs', 'user', 'Logs', 'See everything done under {UserName|Your} account in the portal.', 0, 1, 510),
('user.logs.export', 'user.logs', 'Export', 'Download {UserName|Your} logs.', 0, 1, 511),
('user.recycle', 'user', 'Recycle Bin', 'See {UserName|Your} deleted items from the last 30 days.', 0, 1, 520),
('user.recycle.restore', 'user.recycle', 'Restore', 'Put one of {UserName|Your} deleted items back where it was.', 0, 1, 521),
('user.recycle.export', 'user.recycle', 'Export', 'Download {UserName|Your} recycle bin.', 0, 1, 522),

('calendar', NULL, 'Event Calendar', 'See company events, holidays, leave and project dates.', 0, 1, 600),
('calendar.create', 'calendar', 'Add events', 'Add a company or project event for everyone, a department or chosen members.', 0, 1, 610),
('calendar.download', 'calendar', 'Download calendar', 'Download the calendar as an .ics file.', 0, 1, 620),

('access', NULL, 'Access', 'See {UserName|Your} powers and switch them off and on. This page itself always stays on.', 0, 0, 700),

('manage', NULL, 'Manage', 'Work on other people''s records.', 0, 1, 800),
('manage.requests', 'manage', 'Requests', 'Review other people''s requests. Each kind below reaches only as far as its own setting.', 1, 1, 810),
('manage.requests.leave', 'manage.requests', 'Leave Requests', 'See leave requests.', 1, 1, 811),
('manage.requests.leave.decide', 'manage.requests.leave', 'Approve or reject', 'Approve or reject leave requests, with remarks.', 0, 1, 812),
('manage.requests.overtime', 'manage.requests', 'Overtime Requests', 'See overtime requests.', 1, 1, 813),
('manage.requests.overtime.decide', 'manage.requests.overtime', 'Approve or reject', 'Approve or reject overtime requests, with remarks.', 0, 1, 814),
('manage.requests.offset', 'manage.requests', 'Offset Requests', 'See offset requests.', 1, 1, 815),
('manage.requests.offset.decide', 'manage.requests.offset', 'Approve or reject', 'Approve or reject offset requests, with remarks.', 0, 1, 816),
('manage.requests.reimbursement', 'manage.requests', 'Reimbursement Requests', 'See reimbursement claims and their receipts.', 1, 1, 817),
('manage.requests.reimbursement.decide', 'manage.requests.reimbursement', 'Approve or reject', 'Approve or reject each claim line, with remarks.', 0, 1, 818),
('manage.requests.reimbursement.payout', 'manage.requests.reimbursement', 'Attach payout receipts', 'Attach the proof of payment to an approved claim.', 0, 1, 819),
('manage.requests.flagged', 'manage.requests', 'Flag Reports', 'See reports filed while their author was on a warning.', 1, 1, 820),
('manage.requests.flagged.decide', 'manage.requests.flagged', 'Approve or reject', 'Approve or reject a flagged report, with remarks.', 0, 1, 821),
('manage.requests.late', 'manage.requests', 'Late Reports', 'See reports filed after their day, and whether each was excused.', 1, 1, 822),
('manage.requests.late.decide', 'manage.requests.late', 'Approve or reject', 'Approve or reject a late report, with remarks.', 0, 1, 823),
('manage.requests.notified', 'manage.requests', 'Request alerts', 'Get an inbox alert when someone in {UserName|Your} reach files or changes a request.', 0, 1, 824),
('manage.requests.export', 'manage.requests', 'Export', 'Download the request queue.', 0, 1, 825),
('manage.reports', 'manage', 'Reports', 'Open the timesheets of the people in {UserName|Your} reach.', 1, 1, 830),
('manage.reports.export', 'manage.reports', 'Export', 'Download their reports.', 0, 1, 831),
('manage.reports.unlisted', 'manage.reports', 'Ignore white and black lists', 'Open everyone {UserName|Your} reach covers on Reports, whatever white or black list anyone set on it.', 0, 1, 832),
('manage.users', 'manage', 'Users', 'See the member roster.', 1, 1, 840),
('manage.users.role', 'manage.users', 'Change roles', 'Set a member''s role.', 0, 1, 841),
('manage.users.export', 'manage.users', 'Export', 'Download the roster.', 0, 1, 842),
('manage.users.access', 'manage.users', 'Access Rules', 'Open the Access Rules of the people in {UserName|Your} reach who sit below {UserName|Your} own role.', 1, 1, 843),
('manage.users.access.give', 'manage.users.access', 'Give powers', 'Switch a power on for someone: only one held here, never wider than {UserName|Your} own reach. It ends if that power is ever lost.', 0, 1, 844),
('manage.users.access.limit', 'manage.users.access', 'Limit powers', 'Switch a power off for someone, with everything under it, whoever gave it to them.', 0, 1, 845),
-- Project Approver sits under Manage beside the page it opens, Project Management (2026-10-07).
('projects.manager', 'manage', 'Project Approver', 'Approve or reject the projects waiting on Project Management: the ones {UserName|You} lead, the ones {UserName|You} are on or added, {UserName|Your} department''s, or every project. {UserName|Your} own new projects still wait unless {UserName|You} also auto-approve.', 1, 1, 850),

-- Finance reads the transaction tracker, which is the company's and nobody's own, so it takes no reach.
-- Existing databases: the Laravel migration add_portal_finance_rules.
('finance', NULL, 'Finance', 'See the company''s money: budgets, envelopes and every transaction.', 0, 1, 850),
('finance.overview', 'finance', 'Overview', 'See the finance dashboard: cash flow, the forecast, team balances and the latest transactions.', 0, 1, 851),
('finance.transactions', 'finance', 'Transactions', 'See every transaction in the ledger, with the envelopes and accounts it touched.', 0, 1, 852),
('finance.transactions.export', 'finance.transactions', 'Export', 'Download the transactions list.', 0, 1, 853),
('finance.envelopes', 'finance', 'Envelopes', 'See every envelope and budget code, with what is left in each.', 0, 1, 854),
('finance.envelopes.export', 'finance.envelopes', 'Export', 'Download the envelope and budget code lists.', 0, 1, 855),
-- Existing databases: the Laravel migration add_portal_finance_envelope_write_rules.
('finance.envelopes.edit', 'finance.envelopes', 'Add and edit', 'Add and change budget codes and envelopes, and close or reopen an envelope.', 0, 1, 856),
('finance.envelopes.funds', 'finance.envelopes', 'Record funds', 'Record money an envelope received, credited to it, its budget code and its account.', 0, 1, 857),
-- Existing databases: the Laravel migration add_portal_finance_money_rules.
('finance.accounts', 'finance', 'Accounts', 'See every account with what it received, spent and holds, and the fund transfers between accounts.', 0, 1, 858),
('finance.accounts.funds', 'finance.accounts', 'Add and transfer funds', 'Add money to an account, move money between accounts, and complete or cancel a pending transfer.', 0, 1, 859),
('finance.envelopes.transfer', 'finance.envelopes', 'Transfer funds', 'Move money from one envelope to another, across their owners'' accounts when those differ.', 0, 1, 860),
('finance.transactions.add', 'finance.transactions', 'Add transactions', 'Add income or an expense. An expense on an envelope another account owns leaves a transfer pending to the account that paid.', 0, 1, 861),
-- Existing databases: the Laravel migration add_portal_finance_accounts_add_rule.
('finance.accounts.add', 'finance.accounts', 'Add accounts', 'Open a new account in the transaction tracker, held by a person.', 0, 1, 862),

('administration', NULL, 'Administration', 'Keep the portal in order: logs, trash, emails and backups.', 0, 1, 900),
('administration.recycle', 'administration', 'All Recycle', 'See what the people in {UserName|Your} reach deleted. View only: restoring someone else''s item could backfire on them.', 1, 1, 910),
('administration.recycle.export', 'administration.recycle', 'Export', 'Download the recycle list.', 0, 1, 911),
('administration.recycle.unlisted', 'administration.recycle', 'Ignore white and black lists', 'See everyone {UserName|Your} reach covers on All Recycle, whatever white or black list anyone set on it.', 0, 1, 912),
('administration.logs', 'administration', 'All Logs', 'See what the people in {UserName|Your} reach did in the portal.', 1, 1, 920),
('administration.logs.export', 'administration.logs', 'Export', 'Download the logs.', 0, 1, 921),
('administration.actions', 'administration', 'All Actions', 'See every change the portal recorded for Airtable.', 1, 1, 930),
('administration.actions.sync', 'administration.actions', 'Sync to Airtable', 'Send the recorded changes to Airtable.', 0, 1, 931),
('administration.actions.export', 'administration.actions', 'Export', 'Download the actions.', 0, 1, 932),
('administration.missing', 'administration', 'Missing Reports', 'See who has not filed a report for a day.', 1, 1, 940),
('administration.missing.email', 'administration.missing', 'Email reminders', 'Email a reminder to one person or to everyone missing.', 0, 1, 941),
('administration.missing.mobile', 'administration.missing', 'Mobile reminders', 'Send a reminder to the mobile app.', 0, 1, 942),
('administration.missing.export', 'administration.missing', 'Export', 'Download the missing list.', 0, 1, 943),
('administration.emails', 'administration', 'All Emails', 'See every email the portal sent, who it reached and whether it arrived.', 0, 1, 950),
('administration.emails.export', 'administration.emails', 'Export', 'Download the list of sent emails.', 0, 1, 951),
('administration.backup', 'administration', 'Backup', 'Open the Backup page.', 0, 1, 960),
('administration.backup.create', 'administration.backup', 'Create backups', 'Take a backup of the portal data.', 0, 1, 961),
('administration.backup.download', 'administration.backup', 'Download backups', 'Download a backup file.', 0, 1, 962),
('administration.backup.restore', 'administration.backup', 'Restore backups', 'Put the portal back to a backup.', 0, 1, 963),
('administration.backup.export', 'administration.backup', 'Export', 'Download the backup list.', 0, 1, 964),
('administration.alerts', 'administration', 'Access alerts', 'Get an email when someone is refused access to a page.', 0, 1, 970),

-- Email is its own page after Administration, as in the sidebar; what it sent is Administration / All
-- Emails. Existing databases: the Laravel migration move_portal_email_rules.
('email', NULL, 'Email', 'Open the Email page: write an email and see how it lands before it goes.', 0, 1, 980),
('email.send', 'email', 'Send emails', 'Send the email to everyone, a department, a role, chosen members or typed addresses.', 0, 1, 981),
('email.library', 'email', 'Templates and footers', 'Save, edit and delete email templates and footers.', 0, 1, 982),

('workspace', NULL, 'Data Workspace', 'Open the admin spreadsheet of portal tables.', 0, 1, 1000),
('workspace.private', 'workspace', 'Private columns', 'Read private columns: tax and government numbers, bank details, address and birthday.', 0, 1, 1010),
('workspace.edit', 'workspace', 'Edit cells', 'Change values in the spreadsheet.', 0, 1, 1020),
('workspace.views', 'workspace', 'Shared views', 'Save and delete views everyone can use.', 0, 1, 1030);

-- Administrators and above only: an admin holding it manages fellow admins, and passes it only to them.
-- Developers only: held solely in Developer mode, so nobody can give them, not even another developer.
INSERT INTO access_permissions (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order) VALUES
('manage.users.access.peers', 'manage.users.access', 'Manage other admins', 'Change the role and Access Rules of fellow administrators in {UserName|Your} reach, not only members. Only administrators can hold it.', 0, 1, 'admin', 846),
('administration.logs.developers', 'administration.logs', 'See developers'' activity', 'See what developers did in All Logs and All Actions, sign-ins included. What they did in Developer mode reads "a developer" to anyone not in Developer mode. Only executives and MIS administrators can hold it.', 0, 1, 'admin', 922),
('manage.users.information', 'manage.users', 'Show employee information', 'See the private details of the people in {UserName|Your} reach: birthday, address, contact, government and tax numbers, bank details and emergency contact.', 1, 1, 'admin', 848),
('manage.users.add', 'manage.users', 'Add users', 'Add someone to the portal before Airtable has them, in {UserName|Your} department or any department.', 0, 1, 'admin', 847),
('manage.users.profile', 'manage.users', 'Edit profiles', 'Edit the signature and profile details of the people in {UserName|Your} reach: title, email, phone, department and location.', 0, 1, 'admin', 849),
('manage.users.department', 'manage.users', 'Change departments', 'Move someone to another department: the one their reaches and the projects they may lead follow.', 0, 1, 'admin', 850),
('email.images.global', 'email', 'Global images', 'Add and delete the images everyone can use in an email. Anyone who can open Email can already use them. Only administrators can hold it.', 0, 1, 'admin', 983),
('developer', NULL, 'Developer', 'Tools for the people who build the portal.', 0, 1, 'developer', 1100),
('developer.rules', 'developer', 'Rule catalog', 'Change the rule list and what each role starts with.', 0, 1, 'developer', 1110),
('developer.flag', 'developer', 'Developer flag', 'Mark or unmark someone as a developer.', 0, 1, 'developer', 1120),
('developer.performance', 'developer', 'Performance page', 'Open the performance debug page.', 0, 1, 'developer', 1130);

-- Reports and All Recycle can be narrowed to chosen people with a white or black list (access_lists).
UPDATE access_permissions SET supports_list = 1 WHERE permission_key IN ('manage.reports', 'administration.recycle');

-- Managing fellow admins and changing roles are for administrators and above, and stay with MIS
-- among admins; executives hold them anywhere.
UPDATE access_permissions SET only_department = 'MIS', min_tier = 'admin' WHERE permission_key IN (
    'manage.users.access.peers', 'manage.users.role', 'manage.users.add', 'manage.users.department', 'administration.logs.developers',
    'administration.actions', 'administration.actions.sync', 'administration.actions.export'
);

-- All Backups is parked until Airtable is retired (docs/power/backups-plan.md): developers only, in
-- Developer mode, and no gift reaches it. Existing databases: make_portal_backup_rules_developer_only.
UPDATE access_permissions SET min_tier = 'developer' WHERE permission_key LIKE 'administration.backup%';
-- References is an administrator's tool, like the other Manage pages: admins hold it by preset.
-- Existing databases: open_portal_references_and_shared_pages_rules.
UPDATE access_permissions SET min_tier = 'admin' WHERE permission_key LIKE 'projects.references%';
-- Auto-approve is an admin's at most, and only a named one's (2026-10-07, supervisor): being an admin
-- is not enough, so no role but developer starts with it. Existing databases: split_portal_project_auto_approval.
UPDATE access_permissions SET min_tier = 'admin' WHERE permission_key = 'projects.create.direct';
-- A Completed or Closed project is its department head's alone (2026-10-12, supervisor): no role but
-- developer starts with Edit closed projects. Existing databases: add_portal_edit_closed_projects_rule.
UPDATE access_permissions SET min_tier = 'admin' WHERE permission_key = 'projects.edit.closed';

-- Members: their own work only. View Projects and Track Projects show only the projects they are on.
-- Leading, editing, scoping and deleting projects are powers an admin (or a lead) gives, not a
-- member's own, so none of them is a member default -- that is what stops every member from being a lead.
-- Adding is everyone's (2026-10-06): a member's project waits for a Project Manager who covers it.
-- A member adds for their own department only (2026-10-07): add_portal_member_add_projects_department.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'member', permission_key,
       CASE
           WHEN permission_key IN ('projects.view', 'projects.track') THEN 'own'
           WHEN permission_key = 'projects.create' THEN 'department'
       END,
       0
FROM access_permissions
WHERE permission_key IN (
    'home',
    'projects', 'projects.view', 'projects.track', 'projects.track.export', 'projects.create', 'projects.created', 'projects.created.export',
    'reports', 'reports.daily', 'reports.late', 'reports.submitted',
    'reports.submitted.edit', 'reports.submitted.delete', 'reports.submitted.export',
    'requests', 'requests.own', 'requests.own.edit', 'requests.own.cancel', 'requests.own.export',
    'requests.leave', 'requests.overtime', 'requests.offset', 'requests.reimbursement',
    'user', 'user.logs', 'user.logs.export', 'user.recycle', 'user.recycle.restore', 'user.recycle.export',
    'calendar', 'calendar.download',
    'access',
    -- Every member may write and send an email as themselves (2026-10-15): the composer opens with
    -- Me, their own address and a signature footer, and the page sits before Bug Reports.
    -- Existing databases: grant_portal_email_to_members.
    'email', 'email.send'
);

-- Admins: everything but the developer tools, Backup, the Airtable sync, Manage other admins and
-- Ignore white and black lists and Show employee information,
-- for their own department. Every project, as today, and every department on All Actions, which only
-- MIS admins hold.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'admin', permission_key,
       CASE
           WHEN supports_reach = 0 THEN NULL
           -- Project Approver stays at the admin's own department (plus the projects they lead), 2026-10-07.
           WHEN permission_key IN ('projects.view', 'projects.track', 'projects.edit', 'projects.scopes', 'projects.delete', 'projects.create', 'administration.actions') THEN 'all'
           ELSE 'department'
       END,
       0
FROM access_permissions
WHERE permission_key NOT LIKE 'developer%'
  AND permission_key NOT LIKE 'administration.backup%'
  AND permission_key <> 'administration.actions.sync'
  AND permission_key <> 'manage.users.access.peers'
  AND permission_key <> 'manage.users.information'
  AND permission_key <> 'administration.logs.developers'
  AND permission_key <> 'email.images.global'
  AND permission_key <> 'projects.create.direct'
  AND permission_key <> 'projects.edit.closed'
  AND permission_key NOT LIKE '%.unlisted';

-- Executives: every department and executives' logs, without the developer tools and without the
-- pages that are a member's own work (Home, Reports, Requests, User) or housekeeping (All Recycle,
-- All Actions, Backup). An executive who is a developer still opens those in Developer mode.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'executive', permission_key,
       CASE WHEN supports_reach = 1 THEN 'all' END,
       permission_key = 'administration.logs'
FROM access_permissions
WHERE permission_key NOT LIKE 'developer%'
  AND permission_key NOT IN ('home', 'reports', 'requests', 'user', 'administration.recycle', 'administration.actions', 'administration.backup', 'administration.logs.developers', 'projects.create.direct', 'projects.edit.closed')
  AND permission_key NOT LIKE 'projects.references%'
  AND permission_key NOT LIKE 'reports.%'
  AND permission_key NOT LIKE 'requests.%'
  AND permission_key NOT LIKE 'user.%'
  AND permission_key NOT LIKE 'administration.recycle.%'
  AND permission_key NOT LIKE 'administration.actions.%'
  AND permission_key NOT LIKE 'administration.backup.%';

-- Developers: every rule, every department, and executives' logs.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'developer', permission_key,
       CASE WHEN supports_reach = 1 THEN 'all' END,
       permission_key = 'administration.logs'
FROM access_permissions;

-- Personal defaults (access_personal), matched on id_no like is_developer. What the department heads
-- hold beyond an admin (Nathaniel for MIS, Steven for Engineering) is no longer here: it is the head
-- presets, seeded with company_departments at the end of this file (2026-10-14, user).

-- Project Approver: every admin by preset, and Fin Donasco and Audie Gantuangko over the projects they
-- lead (2026-10-07, supervisor). Their own defaults rather than Steven's gift, so it does not hang on his.
INSERT INTO access_personal (employee_id, permission_key, reach)
SELECT id, 'projects.manager', 'lead' FROM employees WHERE id_no IN ('220825-0004', '230103-0005');
-- A power counts only under a held parent, so they hold the Manage group too; it opens nothing alone.
INSERT INTO access_personal (employee_id, permission_key, reach)
SELECT id, 'manage', NULL FROM employees WHERE id_no IN ('220825-0004', '230103-0005');

-- Steven Gaurano (admin) gives Fin Donasco and Audie Gantuangko what a project lead needs, since none
-- of it is a member default any more: Project Lead for Engineering, and Edit / Manage scopes / Delete
-- for the projects they lead (lead), plus Add projects anywhere. A gift, so it counts only while Steven
-- keeps it; made in Default mode. Both receiver id_nos are active employees loaded from data.sql.
INSERT INTO access_grants (employee_id, permission_key, reach, granted_by)
SELECT receiver.id, grant_row.permission_key, grant_row.reach, giver.id
FROM employees AS receiver
JOIN employees AS giver ON giver.id_no = '220302-0003'
JOIN (
    SELECT 'projects.lead' AS permission_key, 'department' AS reach
    UNION ALL SELECT 'projects.edit', 'lead'
    UNION ALL SELECT 'projects.scopes', 'lead'
    UNION ALL SELECT 'projects.delete', 'lead'
    UNION ALL SELECT 'projects.create', 'all'
) AS grant_row
WHERE receiver.id_no IN ('220825-0004', '230103-0005');



-- ============================================================================
-- PROJECT REFERENCE DATA - Manage / Project Settings
-- departments and job_types are the lists Add/Edit Project offers, editable on that page.
-- They start from what projects and employees already use, and the form unions them with the
-- distinct values on live projects, so a type added there shows up and an old one survives.
-- Existing databases: run the two CREATE statements and their seeds.
-- ============================================================================

CREATE TABLE IF NOT EXISTS departments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_types (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    department  VARCHAR(100) NOT NULL,
    name        VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY job_types_department_name (department, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO departments (name)
SELECT DISTINCT department FROM projects WHERE department IS NOT NULL AND TRIM(department) <> ''
UNION
SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND TRIM(department) <> '';

INSERT IGNORE INTO job_types (department, name)
SELECT DISTINCT department, type_of_job FROM projects
WHERE department IS NOT NULL AND TRIM(department) <> ''
  AND type_of_job IS NOT NULL AND TRIM(type_of_job) <> '';

-- A sentence on what a department does, shown and edited on Manage / References.
-- Portal-only: Airtable's Employees table has no such field, and the sync never sends it.
-- Existing databases: migration add_description_to_portal_departments adds it (and the first sentences).
ALTER TABLE departments
    ADD COLUMN description VARCHAR(255) NULL AFTER name;

-- A first sentence for the departments the company runs today; a blank one is filled in on the
-- page. Only rows with nothing in them are touched, so an edited description survives a re-run.
UPDATE departments
SET description = CASE LOWER(TRIM(name))
    WHEN 'engineering' THEN 'Structural and civil engineering work; its projects pick scopes from the WBS tree.'
    WHEN 'mis'         THEN 'Management information systems: software, data and code-tracked projects.'
    WHEN 'admin'       THEN 'Company administration and support staff.'
    WHEN 'general'     THEN 'Shared activity codes every department may report on.'
    ELSE description
END
WHERE description IS NULL OR TRIM(description) = '';

-- ============================================================================
-- LEAVE TYPES - Manage / References
-- The company's leave vocabulary, editable on References' Leave types tab. The leave form offers
-- exactly this list, so a value is a row here, not a constant in code.
-- Existing databases: run the CREATE and its seed, or migration create_portal_leave_types.
-- ============================================================================

CREATE TABLE IF NOT EXISTS leave_types (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(10)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY leave_types_code (code),
    UNIQUE KEY leave_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO leave_types (code, name) VALUES
    ('01', 'Vacation Leave'),
    ('02', 'Sick Leave'),
    ('03', 'Emergency Leave'),
    ('04', 'Bereavement Leave'),
    ('05', 'Maternity or Paternity Leave');

-- ============================================================================
-- TEAMS - Manage / References
-- The offices a reimbursement line may belong to, editable on References' Teams tab.
-- The reimbursement form offers exactly this list instead of a constant in code.
-- Existing databases: run the CREATE and its seed, or migration create_portal_teams.
-- ============================================================================

CREATE TABLE IF NOT EXISTS teams (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(10)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY teams_code (code),
    UNIQUE KEY teams_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO teams (code, name) VALUES
    ('01', 'Angeles Pampanga Office'),
    ('02', 'Cebu Office');

-- ============================================================================
-- EMPLOYEE SIGNATURES - Manage / Users, the portal's own editable copy
-- Airtable syncs into the portal one way and overwrites the mapped employees columns on every run,
-- so the details an employee's signature shows are kept here instead: edited in the portal and read
-- back as "portal value, else employees value". The first step toward the portal owning the data.
-- ============================================================================

CREATE TABLE IF NOT EXISTS employee_signatures (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT          NOT NULL,
    footer_id    INT          NULL,
    title        VARCHAR(255) NULL,
    email        VARCHAR(255) NULL,
    phone_number VARCHAR(50)  NULL,
    department   VARCHAR(100) NULL,
    location     VARCHAR(100) NULL,
    updated_at   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY employee_signatures_employee (employee_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (footer_id) REFERENCES email_blocks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SHARED PAGES - guest read-only links, made on Manage / Shared Pages
-- A developer picks pages; the server issues a link (/share/<code>) and a
-- password. The guest signs in with the password, plus a code emailed when a
-- bound email is set, and browses those pages read-only. Guests are GET-only.
-- Existing databases: migration create_portal_shared_pages adds whatever is missing (tables, seed, rules, presets).
-- Plan: docs/power/shared-pages-plan.md.
-- ============================================================================

CREATE TABLE IF NOT EXISTS shared_pages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    label           VARCHAR(100) NOT NULL,
    -- What the guest is called on their own screens; null falls back to the label.
    guest_name      VARCHAR(100) NULL,
    code            VARCHAR(64)  NOT NULL,       -- a typed slug, or a generated 24-hex code
    password_hash   VARCHAR(255) NOT NULL,
    -- The generated password, kept readable so the developer can send it again; the hash still verifies.
    password_value  VARCHAR(32)  NULL,
    email           VARCHAR(255) NULL,
    -- With an email bound: 1 asks for a mailed code at every sign-in, 0 only mails the password.
    require_otp     TINYINT(1)   NOT NULL DEFAULT 1,
    expires_at      TIMESTAMP    NULL,           -- NULL only when the developer clears it
    rate_per_minute SMALLINT     NOT NULL DEFAULT 60,
    -- The department a department-reach share is scoped to, snapshotted at save.
    -- Existing databases: add_reach_to_shared_page_rules.
    reach_department VARCHAR(100) NULL,
    revoked_at      TIMESTAMP    NULL,
    created_by      INT          NOT NULL,       -- employees.id, the developer
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    TIMESTAMP    NULL,
    UNIQUE KEY shared_pages_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing databases (the CREATE above already has the column, so a fresh load must not run it):
-- ALTER TABLE shared_pages ADD COLUMN password_value VARCHAR(32) NULL AFTER password_hash;

-- ALTER TABLE shared_pages MODIFY COLUMN code VARCHAR(64) NOT NULL, ADD COLUMN guest_name VARCHAR(100) NULL AFTER label;

-- Existing databases: migration add_require_otp_to_portal_shared_pages runs this.
-- ALTER TABLE shared_pages ADD COLUMN require_otp TINYINT(1) NOT NULL DEFAULT 1 AFTER email;

CREATE TABLE IF NOT EXISTS shared_page_rules (
    share_id INT          NOT NULL,
    rule_key VARCHAR(100) NOT NULL,
    -- How far the granted page reaches for the guest; null for a rule that carries no reach.
    -- Existing databases: add_reach_to_shared_page_rules.
    reach    VARCHAR(16)  NULL,
    PRIMARY KEY (share_id, rule_key),
    FOREIGN KEY (share_id) REFERENCES shared_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email sign-in (2026-10-12): one code to the work email (work only, per the supervisor; personal_code_hash
-- is left from the two-inbox version and stays empty). Existing databases: migration create_portal_login_otps.
CREATE TABLE IF NOT EXISTS login_otps (
    employee_id        INT          NOT NULL PRIMARY KEY,
    work_code_hash     VARCHAR(255) NULL,
    personal_code_hash VARCHAR(255) NULL,
    attempts           TINYINT      NOT NULL DEFAULT 0,
    issued_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at         TIMESTAMP    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shared_page_otps (
    share_id   INT          NOT NULL PRIMARY KEY,
    code_hash  VARCHAR(255) NOT NULL,
    attempts   TINYINT      NOT NULL DEFAULT 0,
    issued_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP    NOT NULL,
    FOREIGN KEY (share_id) REFERENCES shared_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shared_page_visits (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    share_id   INT NOT NULL,
    visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip         VARCHAR(45) NULL,
    FOREIGN KEY (share_id) REFERENCES shared_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which pages a share may grant. Every page-level rule is seeded; is_ready stays 0 until a guest
-- check is run against that page (a guest has no person, so reach pages read all and own pages read
-- empty), and the command that flips a key is the one that maps its guest read in
-- tests/Feature/PortalShareReadinessTest.php. The page keys are the ones RouteRuleConstant maps on
-- the portal side.
CREATE TABLE IF NOT EXISTS shared_page_shareable (
    rule_key VARCHAR(100) NOT NULL PRIMARY KEY,
    is_ready TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO shared_page_shareable (rule_key, is_ready) VALUES
    ('projects.view', 1),
    ('projects.track', 1),
    ('projects.references', 1),
    ('calendar', 1),
    ('manage.requests', 1),
    ('manage.reports', 1),
    ('manage.users', 1),
    ('manage.onboarding', 1),
    ('manage.map', 1),
    ('finance.overview', 1),
    ('finance.transactions', 1),
    ('finance.envelopes', 1),
    ('finance.accounts', 1),
    ('administration.recycle', 1),
    ('administration.logs', 1),
    ('administration.actions', 1),
    ('administration.missing', 1),
    ('administration.emails', 1),
    -- A guest may open the composer read-only and send: the send is the one write they make,
    -- and it lands in All Emails like anyone's.
    ('email', 1);

-- Manage / Shared Pages: a fine-grained, administrator-tier power. The page opens and reads with
-- manage.shares (which shows the link, its QR and the kept password); creating, editing and revoking
-- follow manage.shares.manage, and giving a share a new password follows manage.shares.password.
-- Existing databases: open_portal_references_and_shared_pages_rules.
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.shares', 'manage', 'Shared pages',
     'Create read-only guest links to portal pages: pick the pages, hand out the link and its password.',
     0, 1, 'admin', 860),
    ('manage.shares.manage', 'manage.shares', 'Create and revoke',
     'Create a guest link, change it, and revoke it.',
     0, 1, 'admin', 861),
    ('manage.shares.password', 'manage.shares', 'New passwords',
     'Give a guest link a new password, whose old one stops working at once.',
     0, 1, 'admin', 862);

-- The rows are written after the preset SELECTs above, so their holders are seeded here: admins and
-- developers hold all three, executives none.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'admin', permission_key, NULL, 0 FROM access_permissions WHERE permission_key LIKE 'manage.shares%';
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'developer', permission_key, NULL, 0 FROM access_permissions WHERE permission_key LIKE 'manage.shares%';


-- ============================================================================
-- ONBOARDING - candidate registration with bound links (docs/power/onboarding-plan.md)
-- An administrator creates a candidate entry: a link (typed slug or a generated code), a
-- kept-readable password, and the bound email the OTP is mailed to. The candidate signs in with
-- password + OTP and fills their details, government numbers and documents; the administrator
-- promotes a submitted entry into an employees row. The submitted data lands in new_employee_data.
-- ============================================================================

CREATE TABLE IF NOT EXISTS registrations (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    candidate_name       VARCHAR(150) NOT NULL,
    email                VARCHAR(255) NOT NULL,     -- the bound address; link, OTP, and the email draft go here
    code                 VARCHAR(64)  NOT NULL,     -- a typed slug, or a generated 24-hex code
    password_hash        VARCHAR(255) NOT NULL,
    password_value       VARCHAR(32)  NULL,         -- readable, so the administrator can send it again
    expires_at           TIMESTAMP    NULL,
    revoked_at           TIMESTAMP    NULL,
    created_by           INT          NOT NULL,     -- employees.id, the administrator
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at              TIMESTAMP    NULL,         -- stamped when Send opens the composer
    opened_at            TIMESTAMP    NULL,         -- first verified sign-in
    submitted_at         TIMESTAMP    NULL,
    promoted_at          TIMESTAMP    NULL,
    promoted_employee_id INT          NULL,         -- employees.id after promotion
    employee_data_id     INT          NULL,         -- new_employee_data.id after submit
    details              JSON         NULL,         -- the step-by-step draft; kept after submit
    UNIQUE KEY registrations_code (code),
    INDEX idx_registrations_email (email),
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS register_otps (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT          NOT NULL,
    code_hash       VARCHAR(255) NOT NULL,
    expires_at      TIMESTAMP    NOT NULL,
    attempts        TINYINT      NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS register_documents (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT          NOT NULL,
    kind            VARCHAR(24)  NOT NULL,          -- photo|tin|sss|philhealth|hdmf|national_id|bank
    name            VARCHAR(191) NOT NULL,          -- the candidate's file name
    mime            VARCHAR(127) NOT NULL,
    size_bytes      INT          NOT NULL,
    path            VARCHAR(255) NOT NULL,          -- the private disk, registrations/{id}/
    uploaded_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_register_documents_registration (registration_id, kind),
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The page's powers: an administrator's, split into read, manage and password.
-- Existing databases: create_portal_onboarding_tables (tables, rows and presets together).
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.onboarding', 'manage', 'Onboarding',
     'Guest-free candidate registration: send a bound link, collect details and requirements.',
     0, 1, 'admin', 870),
    ('manage.onboarding.manage', 'manage.onboarding', 'Create and revoke',
     'Create a candidate entry, change it and revoke it.',
     0, 1, 'admin', 871),
    ('manage.onboarding.password', 'manage.onboarding', 'New passwords',
     'Give a candidate link a new password, whose old one stops working at once.',
     0, 1, 'admin', 872);

-- Seeded here because the rows are written after the preset SELECTs above: admins and developers
-- hold all three, executives none.
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'admin', permission_key, NULL, 0 FROM access_permissions WHERE permission_key LIKE 'manage.onboarding%';
INSERT INTO access_presets (role, permission_key, reach, include_executives)
SELECT 'developer', permission_key, NULL, 0 FROM access_permissions WHERE permission_key LIKE 'manage.onboarding%';

-- Manage / Map: where each person last saved something. An administrator's power, at every
-- department by preset so the map opens on everyone; a narrower reach narrows it.
-- Existing databases: add_portal_team_map_rule, reach_for_requests_and_map.
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.map', 'manage', 'Map',
     'See on a world map where each person last saved something in the portal.',
     1, 1, 'admin', 880);
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('admin', 'manage.map', 'all', 0),
    ('developer', 'manage.map', 'all', 0);

-- Allowed activities per department (2026-10-13): Management is a type in four departments, each with
-- its own list; a row with a department wins over the shared row (no department, as Airtable's are).
-- The column is in schema.sql (job_type_allowed_activities.department) and the four Management lists
-- are seeded by migration add_department_to_portal_allowed_activities -- both deliberately kept out of
-- this file: it is appended to data.sql for the Airtable import, where an ALTER on a column schema.sql
-- already creates would fail, and a second INSERT into the table would break the exporter's cell check.

-- Manage Scopes (2026-10-13): members assigned to a project's scopes, and the progress they report.
-- Existing databases: create_portal_scope_assignees_and_progress.
CREATE TABLE IF NOT EXISTS projects_scope_assignees (
    project_id  INT         NOT NULL,
    wbs_code    VARCHAR(32) NOT NULL,
    employee_id INT         NOT NULL,
    PRIMARY KEY (project_id, wbs_code, employee_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects_scope_progress (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    project_id     INT              NOT NULL,
    wbs_code       VARCHAR(32)      NOT NULL,
    employee_id    INT              NOT NULL,
    user_report_id INT              NULL,
    -- The report's day: the newest day wins the scope's progress. Existing databases: add_report_date_to_portal_scope_progress.
    report_date    DATE             NULL,
    percent        TINYINT UNSIGNED NOT NULL,
    created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX projects_scope_progress_latest (project_id, wbs_code, id),
    INDEX projects_scope_progress_report (user_report_id),
    INDEX projects_scope_progress_day (project_id, wbs_code, report_date, id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manage / Map's points: each person's address turned into coordinates once (portal:geocode-homes).
-- `latitude`/`longitude` are the point the map shows -- their own home once they set one, else the
-- address on record. `suggested_*` keep the address on record's own point, which survives them setting
-- a home, so Use default always returns to their data, never the pin they placed (2026-10-17, user).
-- Existing databases: create_portal_employee_home_locations + add_suggested_to_portal_employee_home_locations.
CREATE TABLE IF NOT EXISTS employee_home_locations (
    employee_id         INT           NOT NULL PRIMARY KEY,
    query               VARCHAR(500)  NOT NULL,
    source              VARCHAR(16)   NOT NULL,
    latitude            DECIMAL(10,7) NOT NULL,
    longitude           DECIMAL(10,7) NOT NULL,
    geocoded_at         TIMESTAMP     NULL,
    suggested_query     VARCHAR(500)  NULL,
    suggested_latitude  DECIMAL(10,7) NULL,
    suggested_longitude DECIMAL(10,7) NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Only the head admins promote a submitted candidate, whose account is active at once (2026-10-12):
-- their own defaults, matched on id_no like is_developer, and Developer mode's like every rule.
-- Existing databases: add_portal_onboarding_promote_rule.
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.onboarding.promote', 'manage.onboarding', 'Promote candidates',
     'Turn a candidate who submitted their requirements into an employee on the work email given, able to sign in at once.',
     0, 1, 'admin', 873);
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('developer', 'manage.onboarding.promote', NULL, 0);

-- Activate and deactivate (2026-10-12): an admin turns someone in their Users reach Active or Inactive,
-- never themselves or an executive, a fellow admin only with Manage other admins. Admins, executives and
-- developers hold it. Existing databases: add_portal_users_status_rule.
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.users.status', 'manage.users', 'Activate and deactivate',
     'Turn the account of someone in {UserName|Your} reach active or inactive. An inactive account cannot sign in.',
     0, 1, 'admin', 851);
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('admin', 'manage.users.status', NULL, 0),
    ('executive', 'manage.users.status', NULL, 0),
    ('developer', 'manage.users.status', NULL, 0);

-- Edit employee information (2026-10-12): the private details of the people whose information someone
-- sees; held by those who see it. Portal only.
-- Existing databases: add_portal_users_information_edit_rule.
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.users.information.edit', 'manage.users.information', 'Edit employee information',
     'Change the private details of the people whose information {UserName|You} can see. Airtable keeps its own copy.',
     0, 1, 'admin', 848);
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('executive', 'manage.users.information.edit', NULL, 0),
    ('developer', 'manage.users.information.edit', NULL, 0);

-- Generate ID (2026-10-14, user): the person's ID card, front and back, with their blood type and
-- emergency contact as it prints. Its own power, not Show employee information; admins and above hold
-- it. Existing databases: add_portal_users_id_card_rule.
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.users.id_card', 'manage.users', 'Generate ID',
     'Make the ID card of someone in {UserName|Your} reach, front and back, with their blood type and emergency contact as it prints. Download it as PDF, PNG or Photoshop files.',
     0, 1, 'admin', 852);
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('admin', 'manage.users.id_card', NULL, 0),
    ('executive', 'manage.users.id_card', NULL, 0),
    ('developer', 'manage.users.id_card', NULL, 0);

-- Bug reports (2026-10-13): anyone reports a bug with up to three screenshots; MIS reads and tracks
-- them. Anonymous by design: no employee column and no time finer than the day.
-- Existing databases: create_portal_bug_reports.
CREATE TABLE IF NOT EXISTS bug_reports (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(160) NOT NULL,
    description TEXT         NOT NULL,
    page        VARCHAR(200) NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'new',
    note        TEXT         NULL,
    -- Who in MIS handles it, the semantic version level its fix needs, and the version it shipped in.
    -- Existing databases: add_portal_bug_report_assignee_and_version.
    assignee_id   INT UNSIGNED NULL,
    version_level VARCHAR(10)  NULL,
    fixed_in      VARCHAR(20)  NULL,
    reported_on DATE         NOT NULL,
    updated_at  TIMESTAMP    NULL,
    INDEX bug_reports_status_reported_on_index (status, reported_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bug_report_images (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bug_report_id INT UNSIGNED NOT NULL,
    file_url      VARCHAR(500) NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    size_bytes    INT UNSIGNED NOT NULL,
    FOREIGN KEY (bug_report_id) REFERENCES bug_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The Bug Reports page: every MIS member and admin (only_department), and developers.
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, only_department, sort_order)
VALUES
    ('bug-reports', NULL, 'Bug Reports',
     'Read the bugs people report, never who sent them, and track each one to fixed.',
     0, 1, 'MIS', 990);
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('member', 'bug-reports', NULL, 0),
    ('admin', 'bug-reports', NULL, 0),
    ('developer', 'bug-reports', NULL, 0);

-- Edit transactions (2026-10-13): a transaction's name, date, payor or payee, notes and receipt, never
-- its money; admins, executives and developers hold it like the rest of Finance.
-- Existing databases: add_portal_finance_transactions_edit_rule.
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, sort_order)
VALUES
    ('finance.transactions.edit', 'finance.transactions', 'Edit transactions',
     'Change a transaction''s name, amount, date, payor or payee, notes and receipt. Its type, envelope and account stay as they were.',
     0, 1, 865);
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('admin', 'finance.transactions.edit', NULL, 0),
    ('executive', 'finance.transactions.edit', NULL, 0),
    ('developer', 'finance.transactions.edit', NULL, 0);

-- The blood type and the optional national ID (PhilSys) number a registration asks for (2026-10-12).
-- Portal-only: Airtable's table has neither, and the sync never sends them.
-- Existing databases: add_blood_type_and_national_id_to_portal_new_employee_data.
ALTER TABLE new_employee_data
    ADD COLUMN blood_type     VARCHAR(3)  NULL AFTER date_of_birth,
    ADD COLUMN national_id_no VARCHAR(30) NULL AFTER hdmf_no;

-- The Philippines' provinces, cities and municipalities, and barangays (2026-10-13): the PSGC, for the
-- registration form's address pickers. The rows come from database/data/psgc, loaded by the migration
-- or `php artisan portal:import-places`. Metro Manila stands in as a province for its cities.
-- Existing databases: create_portal_places.
CREATE TABLE IF NOT EXISTS psgc_provinces (
    code CHAR(9)      NOT NULL PRIMARY KEY,
    name VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS psgc_cities (
    code          CHAR(9)      NOT NULL PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    province_code CHAR(9)      NOT NULL,
    INDEX psgc_cities_province_code_index (province_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS psgc_barangays (
    code      CHAR(9)      NOT NULL PRIMARY KEY,
    name      VARCHAR(120) NOT NULL,
    city_code CHAR(9)      NOT NULL,
    INDEX psgc_barangays_city_code_index (city_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- COMPANY DEPARTMENTS AND THEIR HEADS (2026-10-14, user)
-- The departments people work in, one head each. Only MIS and Engineering are departments: Admin and
-- General are project buckets and stay in the departments table above, which the project form,
-- References, scopes and types read. People's department lists (Shared Pages reach, Manage / Users)
-- read this one. A head is found here, not from role_level (PortalDepartments).
-- Existing databases: add_portal_company_departments_and_head_presets.
-- ============================================================================
CREATE TABLE IF NOT EXISTS company_departments (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(100) NOT NULL,
    head_employee_id INT          NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY company_departments_name (name),
    FOREIGN KEY (head_employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO company_departments (name, head_employee_id)
SELECT 'MIS', id FROM employees WHERE id_no = '220202-0002'; -- Nathaniel Tanglao
INSERT IGNORE INTO company_departments (name, head_employee_id)
SELECT 'Engineering', id FROM employees WHERE id_no = '220302-0003'; -- Steven Gaurano

-- Share any page: put any ready page on a guest link at any reach, even one the maker switched off or
-- never held. The MIS head holds everything in truth and only narrows himself day to day.
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.shares.any', 'manage.shares', 'Share any page',
     'Put any ready page on a shared link, at any department or all, even one {UserName|You} switched off or never held.',
     0, 1, 'admin', 863);
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('developer', 'manage.shares.any', NULL, 0);

-- The head presets, laid over a head's admin preset (PortalAccessGraph::startingRules): 'head' for every
-- department head, 'head:<department>' for one department's head. Heads lead and see employee
-- information at every department, auto-approve what they add, alone change a Completed or Closed
-- project of their department, promote candidates and edit employee information. The MIS head also
-- manages other admins, sees developers' activity, runs the Airtable sync and shares any page.
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('head', 'manage.users.information', 'all', 0),
    ('head', 'manage.users.information.edit', NULL, 0),
    ('head', 'manage.onboarding.promote', NULL, 0),
    ('head', 'projects.lead', 'all', 0),
    ('head', 'projects.create.direct', NULL, 0),
    ('head', 'projects.edit.closed', 'department', 0),
    ('head:mis', 'manage.users.access.peers', NULL, 0),
    ('head:mis', 'administration.logs.developers', NULL, 0),
    ('head:mis', 'administration.actions.sync', NULL, 0),
    ('head:mis', 'manage.shares.any', NULL, 0);

-- Profile changes (2026-10-14): anyone changes their own employee information and documents on
-- Settings, and the change waits here until a head admin approves it. Approve profile changes sits
-- under Show employee information: developers by preset, the head admins by their own defaults.
-- Existing databases: create_portal_profile_changes.
CREATE TABLE IF NOT EXISTS employee_change_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT UNSIGNED NOT NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'Pending',
    fields       JSON         NULL,
    documents    JSON         NULL,
    submitted_at DATETIME     NOT NULL,
    decided_at   DATETIME     NULL,
    decided_by   INT UNSIGNED NULL,
    remarks      VARCHAR(500) NULL,
    INDEX employee_change_requests_employee_id_status_index (employee_id, status),
    INDEX employee_change_requests_status_submitted_at_index (status, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.users.information.approve', 'manage.users.information', 'Approve profile changes',
     'Approve or reject the employee information and documents people change about themselves on Settings, before it reaches their record.',
     0, 1, 'admin', 849);
INSERT INTO access_presets (role, permission_key, reach, include_executives) VALUES
    ('developer', 'manage.users.information.approve', NULL, 0);
INSERT INTO access_personal (employee_id, permission_key, reach)
SELECT id, 'manage.users.information.approve', NULL FROM employees WHERE id_no IN (
    '220202-0002', -- Nathaniel Tanglao
    '220302-0003'  -- Steven Gaurano
);
