# 发布到 Nextcloud 应用商店 / GitHub

## 现状（我做完了什么）

本地仓库已经就绪，可以直接推：

```
commit   c3ab471  TodoFolder 0.0.1
tag      v0.0.1
文件     119 个已跟踪（48 个运行时源码 + 截图 + CI + 文档）
忽略     node_modules/ build/ js/*.mjs css/*.css vendor/ composer.lock
产物     build/artifacts/todofolder-0.0.1-signed.tar.gz  409,784 B
         sha256 5a0c562a291eac61384baccc115e61568ea0461f5166c99af2a4b9d4b3a400aa
证书     build/artifacts/todofolder-signing.crt          1,809 B
         sha256 04f76b967dd9a23088187307154a51dfcec5d6e84ce8b2ed97573df97417465c
```

## 为什么最后两步要你来点

你登录的是**内置浏览器 / Edge 的会话**，我从命令行够不到它的 cookie：

- 本机唯一可自动化的 Chrome 调试端口 9333 属于另一个进程（TagSpaces 页面），而且它连 github.com 都被重置；
- DSH 桌面壳只监听 52299（它的 Web GUI），没有 CDP 端口；
- Windows 凭据里只有内网 Gitea（内网 Gitea）的，没有 github.com；
- 商店的上传是 `<input type=file>`，只能由**持有登录态的那个浏览器**选文件。

所以下面两步必须在你已登录的窗口里完成 —— 每步大约 30 秒。

## 第一步：推 GitHub

先在浏览器里建仓库（你已登录）：<https://github.com/new>
- Repository name: `todofolder`
- 不要勾选 README / .gitignore / license（本地已有内容）
- Create repository

然后把这两条命令粘进 Git Bash / PowerShell（**GCM 会弹一次浏览器登录，点同意即可**）：

```bash
cd /c/Users/wangrui/Documents/deepseek-harness/default-workspace/nextcloud-tasknest
git remote add origin https://github.com/<你的用户名>/todofolder.git
git push -u origin main --tags
```

> 我按你商店昵称猜的用户名是 `Remedy233`（该账号存在，0 个公开仓库）。如果不对，替换掉。
> 仓库里的 `appinfo/info.xml` 也写着 `Remedy233`，推完如果发现用户名不同，告诉我，我改完重签一版。

推完确认这两个地址可打开（商店抓截图要用）：
- `https://github.com/<用户名>/todofolder`
- `https://raw.githubusercontent.com/<用户名>/todofolder/main/screenshots/1-tasks.png`

## 第二步：传商店

1. 证书：<https://apps.nextcloud.com/account> → 找 *Certificate* 一栏 → 上传
   `C:\Users\wangrui\Documents\deepseek-harness\default-workspace\nextcloud-tasknest\build\artifacts\todofolder-signing.crt`
   （证书 CN = `todofolder`，必须和 app id 一致，商店会比对）
2. 应用包：开发者页 → *Upload new app* → 选
   `...\build\artifacts\todofolder-0.0.1-signed.tar.gz`
3. 详情页补：分类 Organization / Files / Tools、上面 4 张截图、GitHub Issues 地址
4. 提交审核（首次 1–3 个工作日）

## 之后的版本

```bash
# 改 appinfo/info.xml 版本 → 重建 → 重签 → 上传新 tar.gz
make appstore && make sign
```

私钥 `~/.nextcloud/todofolder.key`（虚机上）丢了就再也发不了更新，**离线备份两份**。

---
要上传的两个文件（都在 `build/artifacts/`）：

| 文件 | 用途 |
|---|---|
| `todofolder-0.0.1-signed.tar.gz` | 应用包本身，含 `appinfo/signature.json` |
| `todofolder-signing.crt` | 签名证书（公钥），商店用它验证包；私钥 `todofolder.key` 只留本机 |

仓库里已核对过的前置条件：

- `id=todofolder` —— 在商店 903 个应用里查过，**未被占用**（`todofile` / `taskfolder` / `todobox` 也都空着）
- 证书 `CN=todofolder` —— 必须与 id 一致，商店的完整性校验会比对
- `signature.json`：43 个文件哈希 + 4096 位 RSA 签名，用证书公钥按 RSA-PSS(SHA-512) 自校验通过
- `appinfo/info.xml` 通过官方 schema 校验
- 包内顶层只有 `appinfo css img js lib LICENSE templates`，无源码/私钥/文档

## 顺序（顺序错了要返工）

1. **先推 GitHub**（截图 URL 指向 `raw.githubusercontent.com`，不推的话商店抓不到截图）
   ```bash
   git init && git add -A && git commit -m "TodoFolder 0.0.1"
   git remote add origin git@github.com:Remedy233/todofolder.git && git push -u origin main
   git tag -a v0.0.1 -m "0.0.1" && git push --tags
   ```
   推完确认这两类地址能打开：
   - `https://raw.githubusercontent.com/Remedy233/todofolder/main/screenshots/1-tasks.png`
   - `https://github.com/Remedy233/todofolder`

2. **上传签名证书**：apps.nextcloud.com → 右上 Account → *API token / Certificate* →
   上传 `todofolder-signing.crt`。证书 CN 必须是 `todofolder`。

3. **上传应用包**：开发者页 → *Upload new app* → 选 `todofolder-0.0.1-signed.tar.gz`。
   商店自己会读 `info.xml` 填好 id、名称、简介、分类，然后拿证书验签。

4. **补元数据**（app 详情页可直接改）：
   - 分类：Organization / Files / Tools（`info.xml` 里已声明）
   - 截图：`info.xml` 的 4 个 `<screenshot>` URL，商店会自动抓；也可以在页面手工上传
   - 描述：`info.xml` 的中英双语长描述
   - 支持/缺陷跟踪：GitHub Issues 地址

5. **等审核**。首次提交通常 1–3 个工作日；被拒最常见的原因是：
   - 证书 CN 与 id 不一致（已避免）
   - 包内缺少 `js/`、`css/` 构建产物或版本号和 tag 不符（`make appstore` 会重建，别手改版本）
   - 截图 URL 404
   - `info.xml` 的 `<dependencies>` 与实际不符（现在是 php ≥ 8.2、Nextcloud 33–35）

## 后续版本

```bash
# 改 appinfo/info.xml 的版本号 → 重建 → 重签 → 上传
make appstore && make sign
# 产物: build/artifacts/todofolder-<版本>.tar.gz
```

私钥 `~/.nextcloud/todofolder.key` 丢了就再也发不了这个 app 的更新，**离线备份两份**。
证书有效期 10 年（到 2036-11），到期前重新生成并上传新证书。

## 顺带确认：应用内显示名

`<name>待办夹</name>`，id 仍为 `todofolder`。Nextcloud 没有按语言切换的 app 名，
商店里英文用户看到的是「待办夹」+ 英文摘要首句，这是当前设计的取舍。

## 一条命令搞定 GitHub（二选一）

### A. 不交 token（推荐，用你自己的浏览器授权）

1. 浏览器建仓库：<https://github.com/new> → 名字 `todofolder`，不勾任何初始化文件
2. 粘这两行（GCM 会弹浏览器，点同意）：

```bash
cd /c/Users/wangrui/Documents/deepseek-harness/default-workspace/nextcloud-tasknest
git remote add origin https://github.com/<你的用户名>/todofolder.git && git push -u origin main --tags
```

3. 打 Release（可选）：`https://github.com/<用户名>/todofolder/releases/new?tag=v0.0.1`，
   附件拖 `build/artifacts/todofolder-0.0.1-signed.tar.gz`

### B. 把 token 给我 / 自己跑脚本（建仓库 + 推送 + Release 一次做完）

生成 token：<https://github.com/settings/tokens> → *Generate new token (classic)* → 勾 `repo`
（或 fine-grained：给这个仓库 Contents + Administration 的写权限）。

```powershell
$env:GITHUB_TOKEN = 'ghp_...'
powershell -NoProfile -ExecutionPolicy Bypass -File deploy\publish-github.ps1 -Owner <用户名> -Repo todofolder
```

脚本会：校验 `appinfo/info.xml` 版本与 tag 一致 → 不存在就建仓库 → `git push main --tags`
（token 只在 URL 里用一次，输出里会被替换成 `***`）→ 建 Release 并附上签名包 →
打印仓库地址和截图 raw 地址。

`-WhatIf` 可以只看它打算做什么；没有 token 时它会在第一步就明确报错，不会动任何东西。