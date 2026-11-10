# 路线图：从「推到 Deck」到「可用」

> 起点 0.0.5：扫描文件夹 → AI 生成标题/摘要/截止日/优先级/标签 → 单向推成 Deck 卡片；
> Deck 侧完成能回流成 `done`。缺的是单任务级动作、筛选排序、错误自救，以及 Deck 只有单行道。

## 0. 决策记录

| # | 决策 | 说明 |
|---|---|---|
| 1 | 要有「进行中」 | 状态机加 `in_progress`，与 Deck 里映射为进行中的列双向对应 |
| 2 | Deck 的列就是分类 | 用户在 Deck 建好列，AI 分析时给出分类建议 |
| 3 | **分类在「标记完成」时才落列** | 工作期所有卡片待在「待办」收件箱；完成时按 AI 建议移入分类列。分类列在状态映射里记为 `done` |
| 4 | 分类建议在分析阶段就生成 | 存 `suggested_stack_id`，完成时直接用 —— 不多花一次模型调用 |
| 5 | Deck 反向对账 15 分钟 | 后台 worker；另有手动「从 Deck 拉取」按钮即时触发 |
| 6 | 删除任务记录默认保留卡片 | 对话框里给「同时删除卡片」勾选 |

工作流全景：

```
文件夹 ──扫描──▶ 任务(pending) ──分析──▶ ready + suggested_stack
                                          │
                              卡片落在工作区 inbox 列（待办）
                                          │
                        用户点「开始」──▶ in_progress（卡片移到「进行中」列）
                                          │
                        用户点「完成」──▶ done（卡片移到 suggested_stack 分类列）
                                          │
                       Deck 里拖回待办 ──▶ ready（卡片位置以 Deck 为准）
```

## 1. 数据模型

### `oc_todofolder_tasks` 新增

| 字段 | 类型 | 说明 |
|---|---|---|
| `deck_stack_id` | BIGINT null | 卡片当前所在列（对账时写回真实值） |
| `stack_source` | STRING(16) | `ai` / `manual` / `fallback` / `inbox` |
| `suggested_stack_id` | BIGINT null | 分析阶段给出的分类建议 |
| `suggested_stack_name` | STRING(64) null | 建议列的名字快照，列被删时用于提示 |
| `suggested_stack_reason` | STRING(255) null | 一句话理由，显示在详情侧栏 |
| `started_at` | BIGINT null | 首次进入进行中的时间 |

### `oc_todofolder_workspaces` 新增

| 字段 | 类型 | 说明 |
|---|---|---|
| `deck_inbox_stack_id` | BIGINT null | 收件箱列（新卡片落这里），空则用 `deck_stack_id` |
| `deck_classify` | BOOLEAN default false | 启用 AI 分类 |
| `deck_classify_timing` | STRING(16) default `on_done` | `on_done` / `on_create` / `manual` |
| `deck_done_target` | STRING(16) default `category` | `category` / `fixed` / `in_place` |
| `deck_category_stacks` | TEXT null | JSON 数组，允许 AI 选的列 id；空 = 全部 |
| `deck_state_map` | TEXT null | JSON：列 id → `todo`/`in_progress`/`done`/`archive` |
| `deck_completion_stack_id` | BIGINT null | `done_target=fixed` 时的完成列 |
| `deck_reconcile_interval` | INTEGER default 15 | 对账间隔（分钟） |

状态值：`pending` · `analyzing` · `ready` · `in_progress` · `done` · `ignored` · `error`

## 2. 状态机

允许的转换（其余一律 409）：

| 从 | 到 | 触发 |
|---|---|---|
| pending | analyzing | 分析开始 |
| analyzing | ready / error | 分析成功 / 失败 |
| error | pending | 重试 |
| ready | in_progress | 用户「开始」或 Deck 拖入进行中列 |
| in_progress | done | 用户「完成」或 Deck 拖入完成/分类列 |
| ready | done | 用户直接「完成」 |
| ready / in_progress | ignored | 用户忽略，或文件夹被删除 |
| ignored | pending | 恢复 |
| done | ready | Deck 拖回待办列，或用户「重新打开」 |

## 3. 提示词契约（自动分列）

用户消息末尾附候选列，模型返回：

```json
{
  "title": "合同回签",
  "summary": "…",
  "due_date": "2026-12-20",
  "priority": "high",
  "tags": ["合同", "法务"],
  "stack": "合同",
  "stack_reason": "材料是合同回签扫描件",
  "reason": "…"
}
```

匹配顺序：精确 → 归一化（去空格、忽略大小写）→ 包含 → 兜底（`stack_source=fallback` 并提示）。
候选列只从 `deck_category_stacks`（为空则看板全部非 inbox/非状态列）里取，提示词里带列名列表。

## 4. 分期

### 第一期 · 0.1.0 —— 单任务精细化 + 分类建议（本迭代）

实施顺序（每步可独立验证）：

1. **迁移** `Version1300Date20261110130000`：上表全部新字段
2. **实体**：`Task` / `Workspace` 加字段、类型与 getter/setter
3. **状态机服务** `TaskStateMachine`：`can(from,to)` + `assert()`，供控制器与 Deck 回流共用
4. **分类建议**：提示词加候选列 → 解析 `stack` → 落 `suggested_stack_*`；`AnalysisService` 不再直接决定落列
5. **Deck 落列**：建卡用 `inbox` 列；`finish` 时按 `done_target` 移动卡片
6. **接口**：
   ```
   POST   /api/tasks/{id}/start            开始
   POST   /api/tasks/{id}/finish           完成（可带 stackId 覆盖分类）
   POST   /api/tasks/{id}/ignore           忽略
   POST   /api/tasks/{id}/restore          恢复
   DELETE /api/tasks/{id}?card=keep|delete 删除记录
   PUT    /api/tasks/{id}/stack            手动指定分类
   GET    /api/tasks/{id}/links            Files 链接 + Deck 卡片链接
   POST   /api/workspaces/{id}/reclassify  只重新分类
   PUT    /api/workspaces/{id}             扩展分类相关设置
   ```
7. **界面**：任务详情侧栏（摘要全文、标签、元信息、建议分类与理由、错误详情、动作按钮）
8. **界面**：行内 `⋯` 菜单（重新分析/开始/完成/忽略/恢复/打开文件夹/打开卡片/删除）
9. **界面**：筛选（状态/优先级/标签）+ 关键词搜索 + 排序 + 逾期标红 + 今天/本周分组
10. **工作区设置**：分类开关、允许的列、分类时机、完成落点、收件箱列、对账间隔
11. **文件夹变化**：改名/移动按 `folder_fileid` 更新路径；删除转 `ignored`

验收：
- 界面上走完「重试 → 详情 → 开始 → 完成 → 忽略 → 恢复」，全程不碰命令行
- 勾选三个分类列后新建文件夹 → 卡片进「待办」；点「完成」→ 卡片自动移到 AI 建议的分类列
- 手动在完成对话框里换分类 → `stack_source=manual`，重新分析不覆盖
- 非法状态转换返回 409

### 第二期 · 0.1.1 —— Deck 双向

1. 列→状态映射生效（拖入进行中列即 `in_progress`）
2. 反向同步标题/截止日/备注（15 分钟对账 + 手动拉取）
3. 冲突：`locked_fields` 优先；Deck 侧改动视为人工意图并加锁
4. 卡片删除 → 忽略；卡片归档 → done
5. 对账摘要写回工作区

### 第三期 · 0.1.2 —— 协作

1. 工作区共享 UI（群组只读/可写）
2. 负责人 ↔ 卡片 assignee
3. 到期提醒（Nextcloud 通知）
4. 任务备注（可选同步为 Deck 评论）

### 第四期 · 0.2.0 —— 规模化

1. 批量操作
2. 服务端筛选分页
3. 夜间全量对账 + 一致性报告
4. `task_events` 审计表
5. 工作区统计（本周新增/完成/逾期）

## 5. 风险

| 风险 | 对策 |
|---|---|
| 列是单值分类，跨类任务二选一 | 提供「固定完成列 + 多标签」模式；标签走 `TagService` |
| 列改名/删除导致建议失效 | 按 `stack_id` 存 + 名字快照；失效时提示并可一键重分类 |
| 反向同步来回抖动 | 比对 `content_hash`/`updated_at`，外部改动才写回，写回不触发回推 |
| 200 条以上列表卡顿 | 第一期前端分组 + 上限提示，第四期上服务端分页 |
| 15 分钟轮询的 Deck 调用量 | 每工作区每次对账 1–2 个只读请求 |
