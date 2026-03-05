# Development & Deployment

## Module Signing

The module ships **pre-signed** so it passes FreePBX integrity checks out of the
box. No signing is needed on the target server — `install.php` handles everything.

### What happens at install time

When a user runs `fwconsole ma install certmanacme`, `install.php` automatically:

1. Copies `signing/certmanacme.sig` to `/etc/freepbx.secure/` (root-owned)
2. Imports `signing/signing-key.pub` into the FreePBX web user's GPG keyring
3. Sets ultimate trust for the signing key

FreePBX then validates the module via `module.sig` → `/etc/freepbx.secure/certmanacme.sig`
using the local signing mechanism (`GPG.class.php:processLocalSig`).

### Signing artifacts

| File | Purpose |
|------|---------|
| `module.sig` | GPG clearsigned INI pointing to the secure sig (hash reference) |
| `signing/certmanacme.sig` | GPG clearsigned INI with SHA256 hashes of all module files |
| `signing/signing-key.pub` | GPG public key, imported at install time |

### Re-signing after changes

After modifying **any** module file, you must re-sign before packaging:

```bash
./sign_module.sh
```

This regenerates all three signing artifacts. Commit them together with your changes.

**Current signing key:** `2DF52C9E1CA424385850B1FE5D2C91139077FDD0` (Lieblinger GmbH)

To use a different key:

```bash
./sign_module.sh <GPG_KEY_FINGERPRINT>
```

### First-time setup (new developer machine)

```bash
# Import the existing signing private key (get it from the team keystore)
gpg --import lieblinger-signing-key.priv

# Or generate a new key (requires re-signing and distributing the new public key)
gpg --full-generate-key   # RSA 4096, no expiry recommended
```

## Packaging

```bash
# Sign, then package
./sign_module.sh
tar czf certmanacme.tar.gz \
    --transform='s,^freepbx-certman-acme,certmanacme,' \
    --exclude='.git*' \
    --exclude='sign_module.sh' \
    --exclude='DEVELOPMENT.md' \
    -C .. freepbx-certman-acme
```

## Deployment

```bash
# Copy tarball to FreePBX server, then:
fwconsole ma install certmanacme
fwconsole chown
fwconsole reload
```

Or copy the module directory directly:

```bash
cp -r certmanacme /var/www/html/admin/modules/
fwconsole ma install certmanacme
fwconsole chown
fwconsole reload
```
