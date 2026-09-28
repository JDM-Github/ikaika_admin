# Send Email global images: power plan

Status: **parked.** For now only a developer in Developer mode can add or delete global images.
The rule below is what it should become. Recorded on 2026-09-29.

## What global images are

The Images panel in Send Email has two lists:

| List | Folder in Cloudinary | Who sees it | Who adds and deletes |
|---|---|---|---|
| Mine | `email-upload/{employee id}/` | only that person | that person |
| Global | `email-upload/global/` | everyone who can open Send Email | see below |

Global holds images the whole company shares, starting with the IKAIKA BIM logo
(`email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4`). The built-in "Company footer" block links to
that logo.

An email stores only the image's link. Deleting a global image therefore breaks it in every email
already sent and in every block that uses it, including the company footer. The panel warns before
it deletes.

## Today

- Anyone who can open Send Email can list global images and insert one.
- Only an employee with `is_developer` who has Developer mode on for this sign-in can upload to or
  delete from global. `PortalDeveloperMode::isOn` decides this.
- Everyone else gets a 403, and the panel hides Upload and Delete while Global is showing.
- The single seam is `PortalEmailImages::canManage`. The list response reports its answer as
  `canManage`, and the portal only reads that flag, so enforcing the rule needs no portal change.

## The rule we want

Adding and deleting global images should belong to:

1. **Executives**, always (`role_level = executive`).
2. **Admins who hold the power.** This is a new, toggleable power under Send Email. An admin does not
   get it by role alone; it is granted.
3. Developers in Developer mode keep it, like every other rule.

Everyone else can still see and insert global images.

## How to enforce it

- Add a permission row under `administration.email`, e.g. `administration.email.images.global`
  ("Global images": "Add and delete the images everyone can use in an email."), with `can_toggle = 1`.
  Add it in `sql/portal/separate.sql` and in a migration for existing databases.
- Presets: developers get it through the existing "every rule" preset. Admins get nothing by default.
- Change `PortalEmailImages::canManage` for the global scope to: the power, or an executive, or
  Developer mode.
- Tests: extend `tests/Feature/PortalEmailImagesTest.php` so each of the three can manage, and a plain
  admin and a member get a 403.

## Open

- `?` Should executives be able to switch the power off for one another, or is it fixed?
- `?` Should a global delete be blocked while a saved block still links to that image?
