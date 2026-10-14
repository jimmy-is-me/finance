# WUMETAX Finance｜家庭財務管理

繁體中文 WordPress 家庭財務 Web App，預設採 **Minimalist Monochrome（黑白極簡）** 視覺設計。無第三方訂閱服務，財務紀錄儲存在自行架設的 WordPress 資料庫。

## 特色

- **家庭空間**：成員登入、邀請、擁有者／編輯者／檢視者權限；每位成員可共同查閱家庭和公司收支
- **雙帳本**：家庭和公司分開統計，整體總覽可一次檢視
- **記帳**：收入、支出、轉帳；分類、帳戶、日期、備註、成員操作紀錄
- **信用卡**：信用卡消費當時記支出，償還帳單以轉帳記錄，避免重複支出
- **分析**：月度統計、支出分類、六個月走勢、近期交易
- **資料**：CSV 匯入／匯出（含 Google Sheet A–M 欄位版面匯入支援），所有財務資料僅存在 WordPress
- **介面**：手機優先 RWD、黑白單色系、襯線字體、清晰線條、零陰影、桌面側邊欄與手機底部導覽、免付費 SaaS 訂閱

## 安裝

1. 下載本儲存庫原始碼 ZIP，或由 GitHub Actions 的 Build Plugin 工作流程下載 `wumetax-finance.zip`
2. WordPress → 外掛 → 安裝外掛 → 上傳 ZIP 並啟用（透過 GitHub Source ZIP 安裝時可能需要先確保 ZIP 頂層為單一外掛目錄）
3. 啟用後自動建立 **家庭財務管理** 頁面（預設路徑 `/family-finance/`）
4. 使用 WordPress 帳號登入，首次開啟建立家庭，之後即可新增收支
5. 在「家庭成員」頁產生邀請連結；受邀者可用既有 WP 帳號加入，或在邀請頁建立新帳號

## 安全與備份

- REST API 使用 WordPress 登入、REST nonce、家庭成員 ACL、欄位驗證及預備陳述式
- 財務資料不會自動傳到此公開 GitHub 儲存庫
- 建議在導入真實資料前先備份 WordPress 資料庫，並先用測試網站驗證；站點務必啟用 HTTPS
- 禁止共享站點管理員帳號；成員應各自登入

## CSV 匯入

支援一般標準化欄位：`日期,範圍,類型,分類,金額,備註,付款方式,帳戶,轉入帳戶`，以及目前 Google Sheet 每月工作表常見的 A–M 欄位版型，匯入時須指定西元年份。**E–G 每日合計欄位不重複計入**，信用卡還款匯入為轉帳。先預覽再確認匯入，避免重覆匯入。

日期格式可為 `YYYY-MM-DD` 或 `M/D`，僅有月／日的日期使用所選年份。

## 技術架構

WordPress 6.2+、PHP 7.4+、MySQL / MariaDB（InnoDB）、原生 JavaScript、CSS、WordPress REST API、自訂資料表。無前端編譯需求。

主要檔案：
- `wumetax-finance.php`：外掛入口與前台路由
- `includes/class-wff-storage.php`：資料表及預設分類
- `includes/class-wff-api.php`：權限、REST API、報表與匯入
- `assets/css/app.css`：Neo-brutalism 設計
- `assets/js/app.js`：RWD 前台互動
- `templates/app.php`：獨立網站入口

## 版本

v1.1.0 — 以使用者提供的 Minimalist Monochrome 規範作為預設外觀，參考 pet-management 2.25.0 的獨立前台、側欄與手機導覽方式；修正超大標題、卡片橫向溢出、窄螢幕多欄和文字擁擠；加上金融管理頁面 no-cache 標頭。保留 v1.0.0 全部記帳與共同家庭功能。

v1.0.0 — 初版。正式資料導入前請先在測試環境完成權限、資料匯入及帳單核對。

### 排版與部署

- Desktop: 232px 側欄（中等螢幕 210px），財務數字卡自動依可用寬度換欄。
- 手機（800px 以下）: 隱藏常駐側欄，顯示底部五個入口與可關閉的側邊抽屜。
- 520px 以下: 2 欄統計卡，表格可單獨橫向捲動，不影響頁面整體寬度。
- 只使用黑、白與輔助灰，不使用彩色面板或陰影，重點以黑底反白、字級和粗線表示。
- WordPress 若使用 LiteSpeed、Cloudflare 或其他快取，請將 `/family-finance/` 和 `/wp-json/wff/v1/*` 排除快取，並檢查防護與登入 Cookie 正常；任何真實站點都應先在測試環境確認記帳權限、手機排版及 CSV 匯入。
- pet-management ZIP 僅作為介面結構參考，未複製其付費模組、字型、私人資料或綑綁依賴。


## License

GPL-2.0-or-later.
