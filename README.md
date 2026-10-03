# Hestia

Full [HestiaCP](https://hestiacp.com) integration for WemX. Each order creates a Hestia user on a Hestia package, and customers manage their hosting from the order page.

Hestia Control Panel is designed to provide administrators an easy to use web and command line interface, enabling them to quickly deploy and manage web domains, mail accounts, DNS zones, and databases from one central dashboard.

> Hestia needs to be installed on a separate, new VPS or machine. See https://hestiacp.com/install.html

## Features

- Connection with the Hestia hostname, port, and an administrator login
- Hestia package picked from the packages on your server
- Optional domain created on the account at checkout
- Email the customer their Hestia username and password
- Disk and bandwidth usage on the order page
- Client tools to create web domains, FTP users, mail domains, email addresses, and MySQL/PostgreSQL databases
- List and create backups
- Shortcuts to the Hestia file manager, DNS, phpMyAdmin, statistics, and cron jobs
- Suspend, unsuspend, terminate, upgrade, and password changes

## Install

Install from the WemX marketplace, or download `Hestia.zip` from a GitHub release and place the `Hestia` folder at `extensions/Servers/Hestia`. Then enable **Hestia**.

Publishing a release builds `Hestia.zip`. Unzipping it creates a folder named `Hestia`. GitHub’s own “Source code” archive still unpacks to `server-hestia-<tag>`. Use `Hestia.zip`.

## Connection

- **Hostname**: the panel URL without the port or a trailing slash, for example `https://panel.example.com`
- **Port**: usually `8083`. Use `443` if Hestia runs without a custom port.
- **Username / password**: an administrator on Hestia

Allow API access from your WemX server's IP in Hestia under **Server Settings → Configure → Security → API**. Keep **Verify SSL** disabled if Hestia uses a self-signed certificate.

## Packages

Pick the Hestia package that sets the account limits. To let customers create their website domain during checkout, add the **Domain** field as a configurable option on the package.
