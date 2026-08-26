# Messenger

A modern messenger-style private messaging extension for **phpBB 3.3**.

It replaces the classic PM inbox with a chat-style interface: conversation list, live polling, smilies, image attachments, optional group chats, and relative timestamps that update while you stay on the page.

**Installation instructions, support, feedback, and suggestions:**  
➡️ **[Messenger topic on phpBB.com](https://www.phpbb.com/community/viewtopic.php?t=2672440)**

> GitHub Issues are disabled for this repository. Please use the phpBB.com topic above for bug reports, questions, and code improvement ideas.

---

## Support & feedback

Please use the official thread on phpBB.com for everything related to this extension:

- **[Messenger support & discussion on phpBB.com](https://www.phpbb.com/community/viewtopic.php?t=2672440)**

That includes:

- Installation help
- Configuration questions
- Bug reports
- Feedback and suggestions for improvements

General phpBB extension help:

- [How to install phpBB extensions](https://www.phpbb.com/support/docs/en/3.3/ug/adminguide/ext_manage/)

---

## Requirements

- phpBB **3.3.x**
- PHP **7.4+**

---

## Installation

> Prefer the walkthrough and discussion on phpBB.com?  
> See the **[Messenger topic](https://www.phpbb.com/community/viewtopic.php?t=2672440)**.

> Important: the folder structure must match the vendor/package name exactly.  
> If the files end up in the wrong place, phpBB will not detect the extension.

### 1. Download

Download the latest release ZIP from this repository (or clone it).

### 2. Upload to the correct path

Copy the extension files into your phpBB board so that `composer.json` is located at:

```text
phpBB root/
└── ext/
    └── negentiendertien/
        └── messenger/
            ├── composer.json
            ├── ext.php
            ├── config/
            ├── styles/
            └── …
```

**Correct final path:**

```text
ext/negentiendertien/messenger/
```

**Common mistakes to avoid:**

| Wrong | Why it fails |
| --- | --- |
| `ext/messenger/` | Missing vendor folder `negentiendertien` |
| `ext/negentiendertien/messenger/messenger/` | One folder too deep (ZIP extracted twice) |
| Uploading only some files into `ext/` | Incomplete package; extension will not enable |

After uploading, you should be able to open this file in a browser or via FTP:

```text
ext/negentiendertien/messenger/composer.json
```

### 3. Enable the extension

1. Log in to the **Admin Control Panel (ACP)**
2. Go to **Customise → Manage extensions**
3. Find **Messenger**
4. Click **Enable**

### 4. Purge the cache

After enabling (or after updating files later):

1. ACP → **General → Purge the cache**  
   or  
2. ACP → **Maintain → Clear cache** (wording may vary by language pack)

Also do a hard refresh in your browser (`Ctrl+F5`) so updated JavaScript/CSS is loaded.

---

## Configuration

1. ACP → **Extensions → Messenger → Settings**
2. Enable the messenger at board level
3. Set options such as:
   - Poll interval
   - Edit after read
   - Delete for both users
   - Visible PM link in topics
   - Open messenger in the User Control Panel
   - Show forum header/footer on the messenger page
   - Group chats (optional)

### Permissions

Grant users/groups the messenger permissions:

- **Can use the messenger** (`u_messenger_use`)
- **Can delete messenger messages for self** (`u_messenger_delete_me`)
- **Can delete messenger messages for both users** (`u_messenger_delete_both`) — including messages sent by the other person

“Delete for both” also requires the ACP board setting **Allow delete for both users** to be enabled. Delete for me hides a message from your own chat only; delete for both hides it for both participants.

Users without `u_messenger_use` keep the standard phpBB private message system.

---

## Updating

1. Disable the extension in ACP (recommended for larger updates)
2. Upload the new files over `ext/negentiendertien/messenger/`
3. Enable the extension again
4. Purge the cache

---

## Uninstall

1. ACP → **Customise → Manage extensions**
2. **Disable** Messenger
3. Optionally **Delete data** (removes extension settings/schema created by migrations)
4. Delete the folder `ext/negentiendertien/messenger/` from the server
5. Purge the cache

---

## License

This extension is released under the **GNU General Public License, version 2** (GPL-2.0-only).  
See [`license.txt`](license.txt) for the full license text.
