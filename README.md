# TodoFolder（待办夹）

把一个 Nextcloud 目录变成任务池：**每个子文件夹就是一个任务**，应用读取里面的文件名与文本，
调用兼容 OpenAI 的模型，自动得出**标题、摘要、截止时间、优先级、标签**，标签写进系统标签
（Files 里能直接看到并筛选），结果可以单向同步成 **Deck 卡片**。

- Nextcloud 33 / 34 / 35，PHP ≥ 8.2
- 依赖（都用得上才装）：`systemtags`（内置）、`deck`（可选，用于发卡）

---

## 1. 它怎么工作

```
工作区（指向一个 Files 目录，例如 /Tasks/客户A）
└── 报价单/            ← 一个任务
│   ├── 说明.md
│   └── 报价_v2.xlsx
└── 合同评审/          ← 另一个任务
│   └── 评审记录.txt
└── _templates/       ← 以 _ 或 . 开头的文件夹被忽略
```

1. **扫描**（定时 / 按钮 / occ）把子文件夹同步成任务行，记录内容哈希；内容变了才重新排队。
2. **分析**取出文件清单 + 可读文本（≤30 个文件、单文件 ≤256 KB、总文本 ≤12000 字），
   一次 JSON 模式调用得到 `title / summary / due_date / priority / tags`。
3. **落库**后写系统标签到任务文件夹，必要时建/更新 Deck 卡片（幂等，认 `deck_card_id`）。

模型端点没配（`ai.base_url` 为空）时，管线走**内置 mock**：同样落库、同样打标签、同样发卡，
只是内容是确定性的占位结论。这条路径用来在没有 API key 时验证全链路。

---

## 2. 安装

```bash
# 拷进应用目录（custom_apps 或 apps-extra）
cp -r todofolder /path/to/nextcloud/custom_apps/todofolder
chown -R www-data:www-data /path/to/nextcloud/custom_apps/todofolder
occ app:enable todofolder
```

首次启用会执行 `lib/Migration/Version1000Date*.php`，建 4 张表：
`todofolder_workspaces` / `todofolder_workspace_shares` / `todofolder_tasks` / `todofolder_task_tags`。

迁移执行过就冻结了（`oc_migrations` 按 `(app, version)` 记账）：改表只能新增迁移文件，
然后 `occ app:disable todofolder && occ app:enable todofolder`。

---

## 3. 配置模型

管理设置 → 待办夹，或命令行：

```bash
occ config:app:set todofolder ai.base_url --value="https://api.deepseek.com/v1"
occ config:app:set todofolder ai.model    --value="deepseek-chat"
occ config:app:set todofolder ai.api_key  --value="sk-..."
occ config:app:set todofolder ai.timeout  --value="60"      # 秒
```

| 键 | 默认 | 说明 |
|---|---|---|
| `ai.base_url` | （空） | 空 = mock；否则 POST 到 `{base_url}/chat/completions` |
| `ai.model` | `deepseek-chat` | 传给端点的模型名 |
| `ai.api_key` | （空） | 作为 `Authorization: Bearer` |
| `ai.timeout` | 60 | 单次请求超时，失败重试一次 |
| `limits.max_files` | 30 | 单任务最多读多少个文件 |
| `limits.max_file_bytes` | 262144 | 单文件最多读多少字节 |
| `limits.max_chars` | 12000 | 送进模型的文本总预算 |
| `deck.enabled` | true | 关掉就完全不碰 Deck |
| `deck.sync_labels` | true | 把 AI 标签同步成 Deck 看板标签（看板级，重名复用） |
| `deck.assign_owner` | true | 把工作区所有者指派到卡片 |
| `deck.link_materials` | true | 卡片描述里列出任务目录与文件的 `/f/<fileid>` 链接 |
| `deck.attach_folder` | true | 把任务目录作为 Deck **附件**挂到卡片（DECK 类型的 share，不复制文件） |
| `deck.prune_labels` | true | 卡片上的标签以任务为准：任务里删掉的标签，卡片上也会去掉 |
| `queue.batch` | 5 | 后台每轮最多分析几个任务 |

局域网端点（vLLM / OneAPI / Ollama）可以直接填内网地址：请求带了
`allow_local_address`，否则 Nextcloud 会拒连私有网段。

---

## 4. 使用

1. 页面左侧「新建工作区」：填名称 + 目录路径（`浏览子目录` 可以点着选）。
2. 「扫描目录」把子文件夹变成任务；「分析未完成」对 `pending` 的跑模型。
3. 每行可以「分析」「编辑」。编辑弹窗里能改 **标题、收到日期、截止日期、优先级、标签**；
   保存后会同时写回系统标签、并立刻同步到 Deck 卡片（标题 / 截止 / 开始日期=收到日期 / 标签）。
   **人工改过的字段会被锁住**（`locked_fields`），之后重新分析不会覆盖它；标签一旦你手工改过，
   分析也不再动标签列表。
4. 想同步到 Deck：点页面上的 **「一键准备看板」**（顶部黄色提示里，或「工作区设置」里）。它会
   复用同名看板、没有就新建、保证有一个「待办」列，然后把已分析的任务一次性推过去。之后每次分析
   都会自动建/更新卡片（幂等，靠 `deck_card_id`）。
   注意：它会忽略 Deck 自带的 `Welcome to Nextcloud Deck!` 示例看板，不会把任务卡丢进示例里。

命令行：

```bash
occ todofolder:scan --all                  # 扫描所有工作区
occ todofolder:scan -w 1                   # 只扫工作区 1
occ todofolder:analyze --limit=5           # 分析 5 个待办任务
occ todofolder:analyze -w 1 -s error       # 只重跑出错的任务
```

后台任务 `OCA\TodoFolder\BackgroundJob\QueueWorker` 每 5 分钟跑一次：挑出「到点了」的工作区
（各自 `scan_interval` 分钟）→ 扫描 → 分析 `queue.batch` 个。需要系统 cron 触发 occ：

```
*/5 * * * * cd /var/www/html && php -f cron.php
```

---

## 5. 权限

- 工作区属于创建者；其他人看不到，除非通过 `todofolder_workspace_shares` 共享给群组
  （只读 1 / 可写 2）。删除工作区永远是 owner 的动作。
- 每次请求都先过 `WorkspaceService::getForUser()`：别人的 id 得到 404，不是数据。
- 任务目录始终在**工作区所有者**的文件树里解析（共享者的路径与 owner 不同）。

---

## 6. 开发

```bash
# 前端（Vite）
npm install
npm run build          # 产物 js/todofolder-main.mjs + css/todofolder-main.css，会清空 js/ 与 css/
npm run dev            # 开发构建（不监听）

# 后端测试（容器里跑，见 tests/README）
composer install
vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit
```

页面模板只放挂载点：`templates/index.php` 里是 `<div id="todofolder-app">`，
`Util::addScript('todofolder', 'todofolder-main')` 会优先找 `js/todofolder-main.mjs`。
改完前端要**硬刷新**：静态资源带远期缓存头。

部署与验证脚本见 `deploy/`。

---

## 7. 排障

| 现象 | 原因 / 处理 |
|---|---|
| 页面 404 | 控制器命名空间不是 `OCA\TodoFolder`，或路由属性写错；`occ app:list` 里有没有 |
| 页面 401（curl） | 路由正常但要登录；curl 需要 `-u user:app-password -H 'OCS-APIRequest: true'` |
| 412 CSRF | 少 `OCS-APIRequest: true` 头 |
| 任务一直 pending | 后台批量为 0：看 `queue.batch`；或 AI 端点没配，`occ todofolder:analyze` 看报错 |
| 分析 error：`任务目录不存在` | 工作区目录被重命名/移动；改回原路径或重建工作区 |
| 标签没出现在 Files | `systemtags` 未启用，或标签不是 user-visible（本应用创建时是 true/true） |
| Deck 报 `Cannot include autoload` | Deck 装的是源码目录而非发行包，缺 `vendor/`；换成 release tarball |
| 新加的接口一直 404，但 `occ router:list` 里能看到 | PHP 进程缓存了旧控制器类：`docker restart nextcloud`（`occ app:enable` 不够） |
| 分析成功但 Deck 里没卡 | 工作区没绑定看板/列。点「一键准备看板」；任务行上也会写这条提示 |
| 下拉框显示 `undefined` | `NcSelect` 的 `label` 属性是「用哪个字段当显示名」，不是标题文字；标题要用独立的 `<span>`/`NcTextField` 写 |
| 导航/列表没有样式、挤成一列 | `cssCodeSplit` 开着的副作用：组件 CSS 被拆进懒加载 chunk。关掉它，样式才会进 `todofolder-main.css` |
| 弹窗按钮不是主题蓝 | `NcDialogButton` 用 `variant="primary"`，`type` 是原生按钮类型（submit/reset/button） |
| Deck 401/403 | 后台任务里没有会话：`DeckBridge` 会以工作区 owner 身份执行，检查该用户对看板有编辑权 |

---

## 8. 安装

**应用商店（推荐）**：管理员 → 应用 → 搜索「待办夹」→ 下载并启用。
之后到「基本设置 → 待办夹」填模型端点（接口地址、模型、API key），或留空先用内置 mock 试跑。

**手动安装**：

```bash
# 把包解到 apps/（或 custom_apps/），目录名必须是 todofolder
tar -xzf todofolder-0.0.1.tar.gz -C /var/www/nextcloud/apps/
chown -R www-data:www-data /var/www/nextcloud/apps/todofolder
sudo -u www-data php occ app:enable todofolder
```

启用后：左侧出现「待办夹」→ 新建工作区（指向一个 Files 目录）→ 扫描目录 → 分析未完成。
想同步到 Deck，点顶部提示里的「一键准备看板」。

## 9. 测试

```bash
# 纯单元测试（不需要 Nextcloud，用 nextcloud/ocp 的接口 + mock）
composer install
composer test:unit

# 端到端（对着真实实例跑）：在工作站上执行，脚本自己 ssh 到虚拟机
TN_USER=alice TN_TOKEN=<app-password> VM=<host> KEY=~/.ssh/id_ed25519 ./deploy/verify.sh
```

`deploy/verify.sh` 的退出码就是失败步数，可以直接当提交门禁。它必须从**能 ssh 到实例的机器**上跑
（脚本内部所有请求都在那台机器上执行）。

## 10. 发布

```bash
make keypair      # 一次性：生成 ~/.nextcloud/todofolder.key/.crt（私钥务必离线备份）
make appstore     # 校验 info.xml → 构建前端 → 打未签名包（本地装这个）
make sign         # 用私钥签名，产出可上传应用商店的包
```

- 上传到 https://apps.nextcloud.com/developer 的是 `build/artifacts/todofolder-<版本>.tar.gz`（含
  `appinfo/signature.json`），证书 `.crt` 也要填到账号里。
- 商店会用 Nextcloud 自己的根证书重签后再分发给实例，所以**本地** `occ integrity:check-app` 对
  自签包报 `Certificate is not valid` 是正常的；本地开发装未签名的那个包即可。
- 版本号只在 `appinfo/info.xml` 里改；`Makefile` 和打包脚本都从那里读。
- 图标、样式和 JS 的 URL 都带版本号，**换图标或改前端必须升版本**，否则浏览器和服务器都拿旧缓存。

GitHub：

```bash
git init && git add -A && git commit -m "TodoFolder 0.0.1"
git remote add origin git@github.com:<you>/todofolder.git && git push -u origin main
git tag -a v0.0.1 -m "0.0.1" && git push --tags
```

`.github/workflows/ci.yml` 会在每次 push 跑：语法检查、`composer validate`、php-cs-fixer、psalm、
PHPUnit（unit）、前端构建。

## 11. 许可

AGPL-3.0-or-later，见 [LICENSE](LICENSE)。

## Languages

The app ships English source strings plus a Chinese translation (`l10n/zh_CN.json`, `zh-CN.json`).
Nextcloud picks the file that matches each user's language setting, so no configuration is
needed: a Chinese user reads Chinese, everybody else reads English. The model prompt follows the
same language, so the generated titles, summaries and tags come out in the user's language.

Adding a language is a matter of dropping `l10n/<lang>.json` next to those two — see
`scripts/i18n-catalogue.cjs` for how the Chinese catalogue is generated from the sources.