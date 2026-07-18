# NCUT eduroam 帳號管理工具

工具檔案：

`NCUT_eduroam_account_manager.html`

## 用途

在本機管理 eduroam / RADIUS 帳號清單，並產生 daloRADIUS `Import Users` 可貼上的 CSV。

## daloRADIUS 匯入設定

在 daloRADIUS 開啟：

`Management > Users > Import Users`

建議選項：

- `Authentication Type`: `Based on username and password`
- `PasswordType`: `Cleartext-Password`
- `Enable Portal Login`: 依需求選擇，eduroam/RADIUS 認證本身不一定需要
- `Group`: 如果要套群組，請先選群組再貼同一批 CSV

CSV 欄位順序：

```text
username,password,email,firstname,lastname,framedipaddress,expiration,department,company,mobilephone,workphone,homephone,address,city,state,country,zip,sessiontimeout,idletimeout,maxdailysession
```

工具輸出的匯入 CSV 不含表頭，可直接貼到 daloRADIUS 的 `CSV Data` 欄位。

## 限制

daloRADIUS `Import Users` 只會新增不存在的帳號。

已存在帳號的改密碼、修改資料、刪除帳號，仍需在 daloRADIUS WebUI 編輯/刪除，或另做直接同步工具。

## 資料安全

此 HTML 工具會把本機清單存在目前瀏覽器的 `localStorage`。如果清單包含密碼，請只在可信任的管理電腦使用，並定期用工具內的 `備份 JSON` 保存或用 `清空` 移除本機資料。
