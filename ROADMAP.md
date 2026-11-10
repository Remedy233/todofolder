# 路线图：从「推到 Deck」到「可用」

> 现状（0.0.5）：扫描文件夹 → AI 生成标题/摘要/截止日/优先级/标签 → 单向推成 Deck 卡片；
> Deck 侧完成状态能回流成 `done`。单条任务没有可执行的动作，列表没有筛选排序，
> 出错只能命令行重跑，Deck 也没有反向通道。

## 0. 已确认的决策

| 项 | 决定 |
|---|---|
| 「进行中」状态 | 要。`in_progress` 与 Deck 的「进行中」列双向对应 |
| Deck 列 = 分类 | 要。用户在 Deck 建好列，AI 分析时自动挑一列落卡；列名就是候选集 |
| Deck 反向对账频率 | 15 分钟（后台 worker），另有手动「从 Deck 拉取」按钮 |
| 删除任务记录 | 默认只删本地记录、卡片保留；对话框里给「同时删除卡片」勾选 |

## 1. 数据模型变更

### 任务表 `oc_todofolder_tasks`

| 新字段 | 类型 | 用途 |
|---|---|---|
| `deck_stack_id` | BIGINT null | 这条任务落在哪一列（不填则用工作区的默认列） |
| `stack_source` | STRING(16) | `ai` / `manual` / `fallback`，决定重新分析时要不要覆盖 |
| `started_at` | BIGINT null | 第一次进入进行中的时间，用于统计与提醒 |

状态值扩展：`pending` · `analyzing` · `ready` · `in_progress` · `done` · `ignored` · `error`

### 工作区表 `oc_todofolder_workspaces`

| 新字段 | 类型 | 用途 |
|---|---|---|
| `deck_classify` | BOOLEAN | 是否让 AI 按列分类 |
| `deck_category_stacks` | TEXT(JSON) | 允许 AI 选的列 id 列表（空 = 看板全部列） |
| `deck_state_map` | TEXT(JSON) | 列 id → 状态（`todo` / `in_progress` / `done` / `archive`） |
| `deck_reconcile_interval` | INTEGER | 对账间隔，默认 15 分钟 |

## 2. 状态机（后端强制校验）

```
pending ──analyze──▶ analyzing ──ok──▶ ready ──start──▶ in_progress ──finish──▶ done
   ▲                     │                               │                        │
   │                     └──fail──▶ error ──retry────────┘                        │
   └────────────────────────── restore ◀── ignored ◀───────┴────reopen─────────────┘
```

规则：

* 只有 `ready` / `in_progress` 可以进入 `done`（Deck 回流除外）
* `done → ready` 只允许由 Deck 回流或用户点「重新打开」触发
* `ignored` 的任务不再被扫描与对账修改，除非用户恢复
* 非法转换返回 409，而不是静默改写

## 3. 分期

### 第一期 · 单任务精细化 + AI 自动分列（0.1.0）

**目标：每条任务都能单独处理；Deck 的列直接当分类用。**

1. 任务详情侧栏：摘要全文、标签、截止日、优先级、`ai_model`、`analyzed_at`、卡片链接、错误详情
2. 行内动作菜单：重新分析 / 开始 / 完成 / 忽略 / 恢复 / 在 Files 中打开 / 打开 Deck 卡片 / 删除记录
3. 列表：状态/优先级/标签筛选 + 关键词搜索 + 按截止日或优先级排序 + 逾期红色 + 今天/本周分组
4. 状态机收敛（见上）+ `in_progress`
5. **AI 自动分列**
   * 工作区设置里勾选「让 AI 按看板列分类」，并勾选哪些列是分类目标
   * 分析提示词附上候选列名，模型返回 `stack` 与 `stack_reason`
   * 匹配顺序：列名精确 → 去空格/大小写 → 包含 → 兜底到工作区默认列（`stack_source=fallback`）
   * 用户在应用里手动换列，或在本机 Deck 里拖动卡片 → `stack_source=manual`，重新分析不再覆盖
   * 单独提供「只重新分类、不重新分析」的入口，避免为了换列再烧一次模型调用
6. 文件夹变化策略：改名/移动按 `folder_fileid` 更新路径；删除则任务转 `ignored` 并在卡片描述标注

验收：任取一条任务，能在界面上完成「重试 → 看详情 → 开始 → 完成 → 忽略 → 恢复」；
在工作区里勾选三个分类列后新建文件夹，AI 落卡到正确列；手动把卡片拖到另一列，
15 分钟内任务列表跟着变。

### 第二期 · Deck 双向精细化（0.1.1）

1. 列 → 状态映射生效：卡片进入映射为 `in_progress` 的列，任务自动转进行中
2. 反向同步标题/截止日/备注（Deck 无 webhook，靠 15 分钟对账 + 手动拉取）
3. 冲突规则：`locked_fields` 优先；Deck 侧改动视为人工意图，自动加锁
4. 卡片删除 → 任务 `ignored`；卡片归档 → 任务 `done` 且标注来源
5. 对账报告：新增/更新/冲突各多少条，写进工作区最近一次对账摘要

验收：在 Deck 里改标题、拖到「进行中」，15 分钟后应用内标题与状态同步；
在应用内改回，下一次对账卡片也变，且不会来回抖动。

### 第三期 · 协作与提醒（0.1.2）

1. 工作区共享 UI（群组只读/可写，复用 `WorkspaceShare`）
2. 负责人：任务 `assignee` ↔ 卡片 assignee 双向
3. 到期提醒：今天到期 / 逾期 N 天 → Nextcloud 通知
4. 任务备注（本地表，可选同步成 Deck 评论）

### 第四期 · 规模化与自动化（0.2.0）

1. 批量操作（多选：重新分析 / 推卡 / 忽略 / 完成）
2. 服务端筛选分页（`?status=&tag=&q=&sort=&limit=&offset=`）
3. 夜间全量对账 + 一致性报告（目录 ↔ 任务 ↔ 卡片）
4. `task_events` 审计表
5. 工作区统计：本周新增/完成/逾期

## 4. 接口增补

```
POST   /api/tasks/{id}/start            开始（→ in_progress，按需移动卡片）
POST   /api/tasks/{id}/finish           完成（→ done，按需移动卡片）
POST   /api/tasks/{id}/ignore           忽略
POST   /api/tasks/{id}/restore          恢复
DELETE /api/tasks/{id}?card=keep|delete 删除记录
PUT    /api/tasks/{id}/stack            {stackId} 手动指定列（→ manual）
GET    /api/tasks/{id}/links            Files 链接 + Deck 卡片链接
POST   /api/workspaces/{id}/reclassify  只重新分类
POST   /api/workspaces/{id}/pull        立即从 Deck 拉取对账
PUT    /api/workspaces/{id}             扩展 deckClassify / categoryStacks / stateMap / reconcileInterval
```

## 5. 提示词变更（自动分列）

```json
{
  "title": "…", "summary": "…", "due_date": "2026-12-20", "priority": "high",
  "tags": ["合同", "待回复"],
  "stack": "合同",              // 必须从候选列里原样返回
  "stack_reason": "材料是合同回签扫描件",
  "reason": "…"
}
```

候选列以列表形式附在用户消息里（`可选列（只能选一个，原样返回）：合同 / 客户A / 财务 / 未分类`）。
模型返回不在候选集里的列名时按匹配顺序回退，并在任务详情里标注「AI 选择失败，已落到默认列」。

## 6. 风险与对策

| 风险 | 对策 |
|---|---|
| 15 分钟轮询给 Deck 带来的调用量 | 每个工作区一次 `boards/{id}` 拉全量，单次约 1 个请求；对账只读不大 |
| AI 分列不稳定 | 固定候选集 + 三次匹配 + 兜底列；手动改动立即加锁不再被覆盖 |
| 列改名导致历史映射失效 | 按 `stack_id` 存，不按名字；改名只影响下一次提示词 |
| 反向同步来回抖动 | 比对 `content_hash` 与 `updated_at`，只有外部改动才写回，且写回时不回推 |
| 几百条任务的列表卡顿 | 第四期上服务端分页，第一期先用前端分组+筛选，超过 200 条给提示 |
