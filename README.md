# WP Foundry Helper (full)

This repository is the **full** helper used by the WP Foundry desktop app (Wails v2).

- WordPress.org listing [foundry-helper](https://wordpress.org/plugins/foundry-helper/) is a **pairing stub only**.
- In-app helper updates download this repo’s default-branch zip.
- WP Foundry 4.2.x looks for `foundry-helper.php` in that zip. Helper 4.0.5 looks for `wpfoundry-helper.php` and its Version header, so that file stays the GitHub plugin entry and loads `foundry-helper.php`.
- Requires WP-CLI on the server and WP Foundry app 2.x.

Current version: **4.2.0**.
