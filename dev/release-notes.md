# GitHub release notes – template

Used when the owner publishes a nuBuilder Next version on GitHub after a gate ("tell me the tag and the release notes").
Not part of nunext.zip (dev/). Neutral wording: no personal names, no company or tool names of uploaded material.

**Last published GitHub release: v0.8.647** – update this line when the owner has published a new one; the next notes cover
everything after it (CHANGELOG.md sections between that version and the gated one).

## Release settings (tell the owner)
- Tag: `v<version>` on the pushed commit (servers only take tags `vX.Y.Z` / `X.Y.Z`)
- Release title: `nuBuilder Next <version>`
- Attach: `nunext.zip` only (exactly that name – the update page looks for it); not `nunext-dev.zip`
- ☑ Pre-release while nuBuilder Next is alpha (servers include pre-releases by default; *Set as the latest release* stays off)
- The release `libraries` is separate and only edited (never a new release); publish new packs there BEFORE a version that needs them

## Structure of the notes (Markdown, pasted as is)
1. One line: "Changes since <last published version>" + link to CHANGELOG.md on `main`.
2. Quote block: alpha warning, back up first, how to install (Admin tools → *Update*: upload or *Look on GitHub*).
3. `### Highlights` – 5–10 bullets, the big features first, each: **name** – what the user gets (one or two lines). Small fixes and
   help / test changes are left out (they are in the changelog). Group related versions into one bullet.
4. `### Security` – only if there are security-relevant changes (CVE, hardening); name the CVE.
5. `### After updating` – steps the admin must do (packs to install, settings to check, behaviour changes to look at, e.g. exports).
   Leave the section out when there is nothing.

## Example (v0.8.418, changes since v0.8.380)

```markdown
Changes since 0.8.380 – full details in [CHANGELOG.md](https://github.com/nuBuilder/nuBuilder-Next/blob/main/CHANGELOG.md).

> ⚠️ Alpha – back up before updating. Install with Admin tools → *Update* (upload `nunext.zip` or *Look on GitHub*).

### Highlights
- **Library packs** – TCPDF, PHPMailer, TinyMCE, phpMyAdmin, Adminer and Tiny File Manager are now separate, signed packs
  (GitHub release `libraries`), installed and rolled back on *Update → Libraries*. TCPDF and PHPMailer are optional; the update page
  says which pack versions a release needs. Third-party code now lives in `vendor/`.
- **Page forms** – forms of type *page* run your own PHP app (`custom/pages/<code>/`) with a stable, versioned page API.
- **Form drafts** – change a form as a draft, preview it, then publish or discard.
- **Concurrent edits** – a warning and a choice (mine / theirs) when someone else saved the same record; shows who else has it open.
- **Forms with AI** – describe a form, paste the AI's JSON answer, check the plan and create the form (no API key needed).
- **Excel export** – lists and form browse lists export to .xlsx (own writer, no extra library); Export menu; print view can hide columns.
- **nuBuilder 4.9 compatibility** – browse SQL kept as nuBuilder does, access conditions, form JavaScript; the installer upgrades
  an uploaded `nubuilder4.sql` of any 4.x version.
- **Update page in tabs** (Update, Backups, History, Libraries, System), GitHub card, "not fully tested" notice for ungated releases.
- **Start page profiles**, formatted notification texts, function reference in the help.

### Security
- Tiny File Manager: CVE-2025-15138 is handled by a locally patched build (pack `2.6+git.4149143.nu3`); a `patched` line in
  `NUNEXT-VERSION.txt` now counts as dealt with in the Security check.
- Excel downloads: safe file names, no half-written files, protection against content-type sniffing.

### After updating
- *Update → Libraries → Look on GitHub* and install the packs you use. A release that needs a newer pack says so on *Check package*.
```
