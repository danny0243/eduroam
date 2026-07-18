# NCUT eduroam

NCUT eduroam RADIUS 管理與臨時帳號申請系統。

## 專案內容

- `eduroam-temp-portal/`：臨時帳號申請、管理者後台、RADIUS Proxy、TANRC 外校封鎖、AD/SQL View 串接與郵件通知功能。
- `eduroam-temp-portal/bin/`：伺服器端維護、同步、遷移與 FreeRADIUS 設定套用工具。
- `eduroam-temp-portal/systemd/`：排程服務與 timer 設定範本。
- `eduroam-temp-portal/sudoers/`：WebUI 可呼叫的受限 sudoers 範本。
- `daloradius-patches/`：daloRADIUS 調整檔。
- `NCUT_eduroam_account_manager.html`：本機 CSV 帳號管理/匯入輔助工具。

## 安全注意

此 repository 不應提交正式密碼、RADIUS shared secret、私鑰、憑證、伺服器備份或資料庫 dump。

已透過 `.gitignore` 排除：

- `clients.conf`
- Ruckus/device backups
- Word/PDF/PNG 渲染輸出
- SSL/private key/certificate 類檔案
- `.env`、log、暫存與依賴目錄

正式機敏值應保留在伺服器端，例如：

- `/var/lib/eduroam-portal/secret.key`
- MariaDB 內加密後的 SMTP、AD、SQL View、RADIUS Proxy shared secret
- `/etc/raddb/clients.conf`
- `/etc/pki/tls/private/`

## 版本控制流程

```bash
git status
git add <changed-files>
git commit -m "describe the change"
git push
```

部署到正式機前，請先完成 PHP lint、FreeRADIUS config check 與服務狀態檢查。
