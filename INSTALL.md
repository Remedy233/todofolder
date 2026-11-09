# 安装 TodoFolder（待办夹）

## 环境要求

| 项目 | 要求 | 怎么查 |
|---|---|---|
| Nextcloud | 33 – 35 | 管理 → 概览，或 `occ status` |
| PHP | ≥ 8.2（用到 `str_starts_with`、`readonly` 等） | `php -v` |
| `systemtags` | 必须启用（内置应用，标签就写在这里） | `occ app:list \| grep systemtags` |
| `deck` | 可选；只有「同步到 Deck 卡片」用得到 | `occ app:list \| grep deck` |
| 后台任务 | 建议 cron 模式，否则定时扫描要靠访问页面触发 | `occ background:cron` |

安装包：`todofolder-0.0.1-local.tar.gz`（未签名，本地安装用）。
它和 GitHub Release 里那个 `todofolder-0.0.1.tar.gz` 内容一致，区别只是后者带 CA 签名、
给应用商店用；本地安装不需要签名，反而别用签名包——证书链还没上商店时会触发完整性告警。

## 方式 A：网页上传（最省事）

1. 以管理员登录 → 右上头像 → **应用**
2. 左侧选 **工具**（或直接在搜索框输入 todo）→ 顶部 **⋯ / 上传应用**（不同版本按钮位置略有差别，
   也可以在「应用」页面右上角找到 `+` 或「从文件安装」）
3. 选择 `todofolder-0.0.1-local.tar.gz` → 等待安装完成
4. 刷新页面，左侧导航出现 **待办夹**

命令行等价操作（装完后看 `occ app:list` 应出现 `todofolder: 0.0.1`）。

## 方式 B：命令行（服务器上）

```bash
# 1) 解包到应用目录，目录名必须是 todofolder（要和 info.xml 的 <id> 一致）
tar -xzf todofolder-0.0.1-local.tar.gz -C /var/www/nextcloud/custom_apps/
#    如果 custom_apps 不存在，用 apps/ 也可以

# 2) 属主必须是 web 用户
chown -R www-data:www-data /var/www/nextcloud/custom_apps/todofolder

# 3) 启用（这一步会跑数据库迁移，建 4 张表）
sudo -u www-data php /var/www/nextcloud/occ app:enable todofolder

# 4) 确认
sudo -u www-data php /var/www/nextcloud/occ app:list | grep todofolder
sudo -u www-data php /var/www/nextcloud/occ todofolder:scan --help
```

Docker 部署的话对应：

```bash
docker cp todofolder-0.0.1-local.tar.gz nextcloud:/tmp/
docker exec nextcloud sh -c 'tar -xzf /tmp/todofolder-0.0.1-local.tar.gz -C /var/www/html/custom_apps/ && chown -R www-data:www-data /var/www/html/custom_apps/todofolder'
docker exec -u www-data nextcloud php occ app:enable todofolder
docker restart nextcloud      # 让 PHP 进程加载新类，漏了这步新路由会 404
```

## 方式 C：从源码（开发用）

```bash
git clone https://github.com/Remedy233/todofolder.git
cd todofolder
npm ci && npm run build          # 产出 js/todofolder-main.mjs 与 css/todofolder-main.css
ln -s "$PWD" /var/www/nextcloud/custom_apps/todofolder
sudo -u www-data php /var/www/nextcloud/occ app:enable todofolder
```

## 装完必做的三步配置

### 1. 填模型端点

**管理 → 基本设置 → 待办夹**（或 `occ config:app:set`）：

| 字段 | 例子 | 说明 |
|---|---|---|
| 接口地址 base_url | `https://api.deepseek.com/v1` | 兼容 OpenAI 的 `/chat/completions`；留空则用内置 mock，全链路可跑但不产出真实结论 |
| 模型 | `deepseek-chat` | |
| API Key | `sk-...` | 只写不读，保存后只显示 `sk-…zG67` 这样的提示 |
| 超时（秒） | `90` | 大文件夹给足时间 |
| 每轮后台分析任务数 | `5` | 后台任务每次处理几个待办 |

也可以用命令行：

```bash
occ config:app:set todofolder ai.base_url --value="https://api.deepseek.com/v1"
occ config:app:set todofolder ai.model --value="deepseek-chat"
occ config:app:set todofolder ai.api_key --value="sk-..."
```

改完点一下设置页的 **测试端点**，返回 `正常：model=… mock=false …ms` 就通了。

### 2. 准备目录

在 Files 里建一个目录，例如 `/Tasks/客户A`，下面每个子文件夹就是一条待办：

```
Tasks/客户A/
├── 报价单/             ← 一条待办
│   ├── 说明.md
│   └── 报价_v2.xlsx
└── 合同评审/           ← 另一条待办
    └── 评审记录.txt
```

以 `_` 或 `.` 开头的文件夹会被忽略（放模板正合适）。

### 3. 建工作区并扫描

左侧 **待办夹** → **新建工作区** → 选刚建的目录 → 保存后点 **扫描目录** → 列表里出现待办 →
点 **分析未完成**。分析完标题、截止时间、标签就有了，标签同时出现在 Files 里。

想要 Deck 卡片：点顶部提示里的 **一键准备看板**（自动建/选看板和「待办」列），再分析即可发卡。

## 验证清单

```bash
occ app:list | grep todofolder          # 0.0.1
occ todofolder:scan --help              # 命令注册成功
curl -I https://你的域名/index.php/apps/todofolder/   # 未登录 302/401，登录后 200
```

界面上：左侧有「待办夹」、能建工作区、能扫描、列表出现待办、任务行上能看到标签。

## 常见问题

| 现象 | 原因 / 处理 |
|---|---|
| 应用列表里看不到 / 装不上 | `info.xml` 的 `<id>`、目录名、`<namespace>` 三者不一致；或 PHP 版本 < 8.2 |
| 装完左侧没入口 | 没刷新页面；或 `occ app:list` 显示 disabled → `occ app:enable todofolder` |
| 新接口 404，但 `occ router:list` 里能看到 | PHP 进程缓存了旧类：`docker restart nextcloud`（或重启 php-fpm） |
| 分析一直是 pending | 后台任务是 ajax 模式，只有访问页面才跑；改 cron：`occ background:cron`，或手动点「分析未完成」 |
| 标签没出现在 Files | `systemtags` 未启用；或标签是本应用建的但 user-visible 关闭（正常情况是 true/true） |
| 卡片没发到 Deck | 工作区没绑定看板/列 → 点「一键准备看板」；或 Deck 未安装/在设置里关掉了同步 |
| 提示「接口地址必须以 http:// 或 https:// 开头」 | base_url 少了协议头 |
| 上传大文件后分析很慢 | 只读前 30 个文件、单文件 ≤256 KB、总文本 ≤12000 字，这是刻意的成本控制；可在 `lib/Service/ContentExtractor.php` 调 |

## 卸载

```bash
occ app:disable todofolder
occ app:remove todofolder        # 删代码；数据表 oc_todofolder_* 保留
```

彻底清干净（连同工作区记录，文件夹里的文件不动）：

```sql
DROP TABLE oc_todofolder_task_tags, oc_todofolder_tasks, oc_todofolder_workspace_shares, oc_todofolder_workspaces;
DELETE FROM oc_appconfig WHERE appid = 'todofolder';
DELETE FROM oc_migrations WHERE app = 'todofolder';
```
