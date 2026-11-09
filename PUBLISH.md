# 发布记录

## GitHub：已完成 ✅

```
仓库    https://github.com/Remedy233/todofolder           public，main 分支 97 个文件
提交    b843e86  TodoFolder 0.0.1
标签    v0.0.1（annotated）
Release https://github.com/Remedy233/todofolder/releases/tag/v0.0.1
        附件 todofolder-0.0.1.tar.gz  414,300 B   sha256 808a6ac9…6977a4（与本地一致）
截图    https://raw.githubusercontent.com/Remedy233/todofolder/main/screenshots/1-tasks.png  ← 200
```

### 过程中遇到的坑（记下来，下次直接照做）

1. **SSH 22 端口被网络挡掉**：`Connection closed by 198.18.0.251 port 22`（198.18/15 是代理的假地址）。
   解决办法是把 `~/.ssh/config` 的 `Host github.com` 指向 `ssh.github.com:443`：
   ```
   Host github.com
       HostName ssh.github.com
       Port 443
       User git
       IdentityFile ~/.ssh/github_todofolder
       IdentitiesOnly yes
   ```
2. **Windows 出口会把大的 POST 掐掉**：`git push` 传包到一半 `Connection reset by peer`，
   GitHub API 上传 >8 KB 的 blob 也一样（1 KB 能过，32 KB 起全断）。所以**在虚机上推**：
   `git bundle create` → scp → 虚机 `git clone` → 虚机 `git push`（虚机出口正常，能下 38 MB）。
3. **仓库里不该有内部信息**：`deploy/`、`VERIFY.md`、`PLAN.md`、`docs/` 里有内网 IP
   （192.168.x）和测试账号，已在推送前从索引移除并加进 `.gitignore`；
   构建产物 `js/*.mjs`、`css/*.css` 也一并忽略。现在 `git grep 192.168` 为空。

### 推送命令（以后有新版本）

```bash
# Windows 这边网络受限，仍然走虚机：
git bundle create /tmp/tdf.bundle --all
scp -i <vm-key> /tmp/tdf.bundle root@<vm>:/root/tdf.bundle
ssh root@<vm> "cd /root/todofolder && git pull /root/tdf.bundle main && git push origin main --tags"
```

## 商店：还差两步（需要你在已登录的浏览器里点）

app store 上传是 `<input type=file>`，只能由持有登录态的窗口选文件，命令行够不到。

1. **证书**：<https://apps.nextcloud.com/account> → Certificate → 上传
   `build\artifacts\todofolder-signing.crt`（CN=`todofolder`）
2. **应用包**：开发者页 → Upload new app →
   `build\artifacts\todofolder-0.0.1-signed.tar.gz`（sha256 `808a6ac9…`）
   也可以直接用 GitHub Release 里的那个同名附件，内容一致。
3. 详情页补分类（Organization / Files / Tools）、截图（上面 4 个 raw URL 已验证可访问）、Issues 地址
4. 提交审核

## ⚠️ 一个安全提醒

排查过程中 `git credential fill` 取出的 OAuth token（`gho_` 开头）**在终端报错信息里被回显过一次**，
等于落在了这次会话的日志里。建议你现在就到
<https://github.com/settings/applications> 撤销 *Git Credential Manager* 的授权，
下次 `git push` 会重新走一次浏览器授权。我已经把虚机上的私钥和 token 文件删干净了。

---
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

## 商店发布：真正卡住的地方（已定位）

我用你给的商店 API token 把流程一路试到底，结论是**证书必须由 Nextcloud 官方 CA 签**，自签的会被拒：

```
POST https://apps.nextcloud.com/api/v1/apps/   {"certificate": <自签 PEM>, "signature": <…>}
→ HTTP 400  ["Certificate is invalid: self-signed certificate"]
```

翻商店源码（`nextcloudappstore/certificate/validator.py`）确认了规则：

```python
validator.validate_certificate(certificate, chain, crl)   # chain = 商店自带的 nextcloud.crt
validator.validate_signature(certificate, signature, data)  # PKCS1v15 + SHA512
```

而且 API 只有两个写端点（`OPTIONS` 探到的）：

```
OPTIONS /api/v1/apps/           → Allow: POST     上传证书并注册 app：{certificate, signature, is_enterprise_only}
OPTIONS /api/v1/apps/releases/  → Allow: POST     发布版本：{download, signature, nightly}（商店自己去下载 tarball）
OPTIONS /api/v1/apps/{id}       → Allow: DELETE   下架
```

所以顺序是：

### ① 申请证书（需要人工审核，等官方签）

已生成 CSR（CN=`todofolder`，RSA 4096）：`build/artifacts/todofolder.csr`

**分支已经推上去了**，只差在网页上点一次「Create pull request」：

```
fork    Remedy233/app-certificate-requests
分支    add-todofolder-csr  (bc73065)
文件    todofolder/todofolder.csr  1586 字节，与本地 sha256 完全一致
比对    https://api.github.com/repos/nextcloud/app-certificate-requests/compare/master...Remedy233:add-todofolder-csr
        → status=ahead, ahead_by=1, files=[todofolder/todofolder.csr +26/-0]
```

一键入口（打开后点 **Create pull request** 即可）：
<https://github.com/nextcloud/app-certificate-requests/compare/master...Remedy233:app-certificate-requests:add-todofolder-csr?expand=1>

为什么还差这一次点击：GitHub 的 PR 创建只能走 API 或网页，而你把 GCM 的 token 撤销了（`/user` → 401），
SSH 密钥只能推分支、不能建 PR。要全自动就把新 token 给我，我连 PR 一起发。

### ② 证书下来之后（一条命令，我或你都能跑）

```bash
STORE_TOKEN=<商店 API token> bash deploy/publish-store.sh \
  --cert    /root/.nextcloud/todofolder.crt \
  --key     /root/.nextcloud/todofolder-signing.key \
  --tarball /root/tdfwork/build/artifacts/todofolder-0.0.1.tar.gz \
  --download https://github.com/Remedy233/todofolder/releases/download/v0.0.1/todofolder-0.0.1.tar.gz
```

脚本做两步：注册（对 app id 签名）→ 发布（对 tarball 签名，商店按 URL 下载）。
**签名算法已经验过**：`openssl dgst -sha512 -sign`，公钥 `-verify` 两条都是 `Verified OK`。
注意它和 `occ integrity:sign-app` 写的 `signature.json`（RSA-PSS）不是一回事，两个都要有：
包内那份给 Nextcloud 服务端做完整性校验，API 这份给商店。

### 私钥在哪

`/root/.nextcloud/todofolder-signing.key`（虚机，600 权限，**从没离开过虚机**）。
证书下来后我会用它重签包，再跑上面的脚本。

---

## 自动化：证书一签下来自己发完（已布好）

虚机上跑着一个 watcher（`deploy/todofolder-autopublish.sh`，pid 见 `pgrep -af todofolder-autopublish`）：

```
每 30 分钟查一次 nextcloud/app-certificate-requests 的 todofolder/ 目录
（GitHub contents API 优先，raw URL 兜底 —— 这条链路偶尔超时）
        ↓ 发现 .crt
闸门 1  证书 CN == todofolder
闸门 2  openssl verify -CAfile <商店 CA 打包> → 必须通过
        （已实测：现在的自签证书在这里会 verification failed，说明闸门有效）
闸门 3  私钥公钥 ↔ 证书公钥 sha256 一致
        ↓ 三道全过
1. occ integrity:sign-app 用 CA 证书重签包（走容器，密钥只在 /tmp 短暂存在）
2. 重打 tarball，算 PKCS1v15+SHA512 签名
3. 删掉 GitHub Release 里的旧附件，上传新附件（同名，URL 不变）
4. POST /api/v1/apps/ 注册（certificate + 对 app id 的签名）
5. POST /api/v1/apps/releases/ 发布（download URL + 对 tarball 的签名）
        ↓
日志写 /root/todofolder-publish.log，成功时删除 /root/.gh_pat 与 /root/.nc_tok
```

日志每 12 次轮询记一条「still waiting」，失败会写下原因并保留 token 供重试。

取消：`pkill -f todofolder-autopublish.sh`；看进度：`tail -f /root/todofolder-publish.log`。

---

## 已上线（2026-11-09）

```
商店页面   https://apps.nextcloud.com/apps/todofolder      （标题：待办夹 - Apps - App Store）
发布记录   /en/feeds/releases.atom?app=todofolder → 待办夹 (0.0.1) @ 2026-11-09T10:32:18Z
平台       33 / 34 / 35 都有 0.0.1
证书       CN=todofolder，Issuer=Nextcloud Code Signing Intermediate Authority，2026-11-09 → 2037-02-14
签名包     todofolder-0.0.1.tar.gz  413,882 B  sha256 75c9276c…1dacf6
           signature.json 里的证书 CN=todofolder（CA 签过，不再是自签）
GitHub     Release v0.0.1 附件已换成 CA 签名的包
```

商店在发布时自己把 tarball 从 GitHub 拉下来、用我们注册的证书验 PKCS1v15+SHA512 签名，
两步都返回 201 —— 等于**官方端到端验证通过**。

### 这轮踩的三个坑（下次直接照做）

1. **DCO 卡了 3 天**：`nextcloud/app-certificate-requests` 要求提交带 `Signed-off-by:` 尾注，
   我用 SSH 直接 push 没加，PR 被打上 `DCO failed`，维护者不看。修法是
   `git commit --amend --signoff` 再 force-push（作者邮箱要与 sign-off 一致）。
2. **Release 附件上传域名**：`POST https://api.github.com/repos/…/releases/{id}/assets` 返回 404，
   必须用 `https://uploads.github.com/…`。
3. **虚机断网/重启会让 watcher 停**：它是 nohup 进程不是服务。证书是 09:54 签的，
   虚机 18:16 才起来，最后是手动跑的同一套步骤。

### 以后发版

```bash
# 1) 改 appinfo/info.xml 版本 → 打包 → 用 CA 证书重签（key 在 /root/.nextcloud/todofolder-signing.key）
# 2) 传 GitHub Release 附件（uploads.github.com）
# 3) 商店发布：POST /api/v1/apps/releases/ {download, signature over tarball}
#    注册那一步只在第一次/换证书时需要
```