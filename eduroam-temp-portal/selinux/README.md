# SELinux policy modules

## ncut_eduroam_httpd_sudo

Allows the Apache/PHP-FPM `httpd_t` domain to run the tightly scoped roaming
blocklist sync helper through sudo on Rocky Linux with SELinux enforcing.

Install:

```bash
cd /path/to/eduroam-temp-portal/selinux
checkmodule -M -m -o ncut_eduroam_httpd_sudo.mod ncut_eduroam_httpd_sudo.te
semodule_package -o ncut_eduroam_httpd_sudo.pp -m ncut_eduroam_httpd_sudo.mod
sudo semodule -i ncut_eduroam_httpd_sudo.pp
```

The matching sudoers file is:

```text
/etc/sudoers.d/ncut-eduroam-roaming-blocklist
```
