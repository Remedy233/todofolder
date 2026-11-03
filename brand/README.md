# 图标候选

六个方案，形状都在 32 单位网格上画成**填充路径**（Nextcloud 用 Imagick 把图标转成
标签页 `.ico`，纯描边会被画成空方块）。

每个方案三个文件：

| 文件 | 用在哪 |
|---|---|
| `<id>.svg` | 中性版（`fill="#000"`），改色用 |
| `<id>-white.svg` | 顶部彩色表头、应用菜单（`img/app.svg`） |
| `<id>-dark.svg` | 浅色背景、设置侧栏（`img/app-dark.svg` / `img/tasknest.svg`） |
| `<id>-favicon.svg` | 浏览器标签页：自带圆角蓝底 + 白色字形，浅/深主题都不会消失 |

对比图：[../docs/icon-candidates.png](../docs/icon-candidates.png)（尺寸矩阵）、
[../docs/icon-candidates-context.png](../docs/icon-candidates-context.png)（标签页 / 菜单 / 侧栏）。

## 方案

| id | 造型 | 说明 |
|---|---|---|
| `a-bowl-check` | 巢碗 + 对勾 | 线上这版。16px 略挤 |
| `b-nest-eggs` | 巢碗 + 三颗蛋 | 最像"巢"，但没有任务语义，16px 蛋会糊成一团 |
| `c-bold-bowl-check` | 巢碗 + 对勾（加粗） | a 的加强版，16px 最稳，推荐 |
| `d-nest-twigs` | 巢碗 + 巢枝 + 对勾 | 细节多，16px 线条会粘在一起 |
| `e-card-check` | 任务卡 + 对勾 | 语义最直接（一张卡 = 一个任务），和 Deck 的图标有点接近 |
| `f-folder-check` | 文件夹 + 对勾 | 呼应"每个子文件夹是一个任务"，和 Deck/Tasks 都不撞 |

## 应用某个方案

```bash
bash brand/apply.sh c-bold-bowl-check     # 换成对应方案
npm run build && node scripts/make-favicon.mjs   # 重新生成 img/favicon.png（128px）
```

`apply.sh` 会把选中的三个变体写进 `img/`，并把 `appinfo/info.xml` 的版本号 +1
（图标 URL 带版本号，不升版本浏览器和服务器都会拿旧缓存）。
