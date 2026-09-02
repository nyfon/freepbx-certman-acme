# ACME Certificate Manager for FreePBX

A FreePBX module that provides **DNS-01 ACME certificate management** by wrapping [acme.sh](https://github.com/acmesh-official/acme.sh).

## Features

- **DNS-01 challenge** — no need to open port 80 or modify firewall rules
- **150+ DNS providers** supported out of the box (Hetzner Cloud, Cloudflare, AWS Route53, etc.)
- **Auto-discovery** of DNS providers from bundled acme.sh scripts
- **Multiple CA support** — Let's Encrypt, ZeroSSL, Buypass, Google Trust Services
- **Automatic renewal** via cron
- **Native certman integration** — certificates appear in FreePBX Certificate Manager
- **CLI + Web UI** for full management

## Installation

1. Copy this module to your FreePBX modules directory
2. Install via `fwconsole ma install certmanacme`
3. acme.sh is downloaded automatically during installation
4. If the download fails (e.g., no internet), retry via:
   - **CLI:** `fwconsole certacme --update-acme`
   - **UI:** Admin → ACME Certificates → Settings → "Download / Update acme.sh"

## CLI Usage

```bash
# List available DNS providers
fwconsole certacme --providers

# Issue a certificate
fwconsole certacme --issue \
    -d pbx.example.com \
    --dns dns_hetznercloud \
    --env HETZNER_TOKEN=your-api-token \
    --server letsencrypt

# Issue with SANs
fwconsole certacme --issue \
    -d pbx.example.com \
    --san alt.example.com \
    --dns dns_cf \
    --env CF_Token=xxx \
    --env CF_Account_ID=yyy

# List managed certificates
fwconsole certacme --list

# Renew all due certificates
fwconsole certacme --renew

# Force renew a specific certificate
fwconsole certacme --renew -d pbx.example.com --force

# Re-deploy certificate to FreePBX
fwconsole certacme --deploy -d pbx.example.com

# Delete a certificate
fwconsole certacme --delete -d pbx.example.com

# Download/update acme.sh
fwconsole certacme --update-acme
```

## Web UI

Navigate to **Admin → ACME Certificates** in the FreePBX web interface.

## How It Works

1. **acme.sh** (bundled) handles ACME protocol, DNS-01 challenges, and certificate issuance
2. This module deploys certificates into FreePBX's expected file layout (`/etc/asterisk/keys/`)
3. Certificates are registered in certman's database (type `up`) for native integration
4. Asterisk, Apache, and HAProxy are automatically reloaded after deployment

## Module signing

This module ships unsigned. FreePBX treats an unsigned module as a banner in Module Admin and
nothing more -- only a *revoked* signature actually blocks a module from loading.

If you want tamper-detection on a particular box, local-sign it there:

```bash
/usr/src/devtools/sign.php /var/www/html/admin/modules/certmanacme --local <keyid>
```

The hash list lands in `/etc/freepbx.secure/certmanacme.sig` and is valid for that machine only.
The signing key must be present in the asterisk user's GPG keyring (`/home/asterisk/.gnupg`) but
must **not** be given ultimate ownertrust, or verification silently takes a path that reports the
module as tampered.

### Upgrading from 17.0.2

17.0.2 and 17.0.3 shipped a pre-signed `module.sig` and installed a signature into
`/etc/freepbx.secure/certmanacme.sig`. That scheme is gone. The leftover file is inert -- with no
`module.sig` present FreePBX never reads it -- but you can remove it:

```bash
rm -f /etc/freepbx.secure/certmanacme.sig
```

## License

GPLv3+
