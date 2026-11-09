// One-off migration: Chinese source strings -> English source strings + zh_CN catalogue.
// Run from the app root: node scripts/i18n-en-source.cjs
const fs = require('fs')
const path = require('path')

const DICT = {
	'待办夹': 'TodoFolder',
	'已保存': 'Saved',
	'模型': 'Model',
	'API Key': 'API key',
	'超时（秒）': 'Timeout (seconds)',
	'每轮后台分析任务数': 'Tasks analyzed per background run',
	'启用 Deck 同步': 'Enable Deck sync',
	'保存': 'Save',
	'测试端点': 'Test endpoint',
	'工作区': 'Workspace',
	'新建工作区': 'New workspace',
	'搜索工作区': 'Search workspaces',
	'删除工作区': 'Delete workspace',
	'一键准备看板': 'Prepare board',
	'扫描目录': 'Scan folder',
	'分析未完成': 'Analyze pending',
	'工作区设置': 'Workspace settings',
	'还没有工作区': 'No workspaces yet',
	'还没有任务': 'No tasks yet',
	'分析': 'Analyze',
	'编辑': 'Edit',
	'更新 Deck 卡': 'Update Deck card',
	'建 Deck 卡': 'Create Deck card',
	'工作区名称': 'Workspace name',
	'目录路径': 'Folder path',
	'浏览子目录': 'Browse folders',
	'取消': 'Cancel',
	'Deck 看板': 'Deck board',
	'Deck 列': 'Deck column',
	'分析完成后自动建卡': 'Create cards automatically after analysis',
	'后台扫描间隔（分钟）': 'Background scan interval (minutes)',
	'允许后台定时扫描': 'Allow scheduled background scans',
	'修正任务': 'Fix task',
	'标题': 'Title',
	'收到日期': 'Received date',
	'截止日期': 'Due date',
	'优先级': 'Priority',
	'标签': 'Tags',
	'删除': 'Delete',
	'截止 {date}': 'Due {date}',
	'收到 {date}': 'Received {date}',
	'出错': 'Error',
	'待分析': 'Pending',
	'分析中': 'Analyzing',
	'分析中…（每批 5 个）': 'Analyzing… (5 per batch)',
	'忽略': 'Ignored',
	'完成': 'Done',
	'已完成': 'Completed',
	'扫描中…': 'Scanning…',
	'扫描完成：新增 {created}，更新 {updated}，移除 {removed}': 'Scan finished: {created} added, {updated} updated, {removed} removed',
	'分析「{name}」…': 'Analyzing “{name}”…',
	'已分析 {n} 个': '{n} analyzed',
	'失败 {n} 个': '{n} failed',
	'失败：{reason}': 'Failed: {reason}',
	'推送 {n} 张卡': '{n} cards pushed',
	'正常：model={model}，mock={mock}，{ms}ms': 'OK: model={model}, mock={mock}, {ms}ms',
	'未设置（当前使用 mock）': 'Not set (using the mock)',
	'已设置：{hint}（留空表示不修改）': 'Set: {hint} (leave empty to keep it)',
	'已保存，Deck 卡片也已更新': 'Saved, Deck cards updated too',
	'Deck 已同步': 'Deck in sync',
	'已复用看板': 'Reused the existing board',
	'已新建看板': 'Created a new board',
	'新建了「待办」列': 'Created the “Todo” column',
	'复用「待办」列': 'Reused the “Todo” column',
	'Deck 就绪': 'Deck ready',
	'正在准备 Deck 看板…': 'Preparing the Deck board…',
	'准备看板失败': 'Preparing the board failed',
	'测试中…': 'Testing…',
	'点「扫描目录」把子文件夹同步成任务，再点「分析未完成」让模型补上标题、日期与标签。':
		'Use “Scan folder” to turn subfolders into tasks, then “Analyze pending” to let the model fill in title, dates and tags.',
	'新建一个工作区，指向存放任务的目录：它下面的每个子文件夹就是一个任务。':
		'Create a workspace that points at the folder holding your tasks: every subfolder becomes one task.',
	'这个工作区还没绑定 Deck 看板，分析结果不会自动变成卡片。':
		'This workspace is not linked to a Deck board yet, so results will not become cards automatically.',
	'模型走兼容 OpenAI 的接口。接口地址留空时使用内置 mock，用来在没有真实模型时验证全链路。':
		'The model is called through an OpenAI-compatible API. Leave the endpoint empty to use the built-in mock, which exercises the whole chain without a real model.',
	'留空表示没有期限；与 Deck 卡片的截止日期互通':
		'Leave empty for no deadline; kept in sync with the Deck card due date',
	'默认是文件夹第一次出现的那天；会同步成 Deck 卡片的开始日期':
		'Defaults to the day the folder first appeared; synced to the Deck card start date',
	'相对你的文件根目录，例如 Tasks/客户A': 'Relative to your files root, for example Tasks/Acme',
	'用逗号或空格分隔；保存后写回系统标签并同步成 Deck 看板标签':
		'Separate with commas or spaces; saved back as system tags and synced to Deck board labels',
	'用模型分析任务目录里的文件，生成标题、截止日期与标签，并同步到 Deck。':
		'Analyze the files inside each task folder, produce title, due date and tags, and sync them to Deck.',
	'接口地址（base_url）': 'Endpoint (base_url）',
	'「{name}」的任务记录会一起删除，文件不受影响。':
		'The task records of “{name}” are deleted too; the files are not touched.',
}

const SRC = path.join(__dirname, '..', 'src')
const used = new Set()
let replaced = 0

for (const file of fs.readdirSync(SRC)) {
	const full = path.join(SRC, file)
	if (!/\.(vue|js)$/.test(file)) continue
	let text = fs.readFileSync(full, 'utf8')
	const before = text
	text = text.replace(/(?<!\w)t\(\s*'((?:[^'\\]|\\.)*)'/g, (match, zh) => {
		used.add(zh)
		const en = DICT[zh]
		if (!en) return match
		replaced++
		// keep the call shape intact: `this.t('x')` stays a method call
		return match.replace(`'${zh}'`, `'${en.replace(/'/g, "\\'")}'`)
	})
	if (text !== before) fs.writeFileSync(full, text)
}

const missing = [...used].filter((zh) => !DICT[zh])
console.log(`replaced ${replaced} occurrences, ${used.size} distinct strings`)
if (missing.length) {
	console.log('MISSING translations:')
	missing.forEach((m) => console.log('  ' + m))
}

// zh_CN catalogue: English source -> Chinese translation
const catalogue = {}
for (const [zh, en] of Object.entries(DICT)) {
	if (!used.has(zh)) continue
	catalogue[en] = zh
}
fs.mkdirSync(path.join(__dirname, '..', 'l10n'), { recursive: true })
fs.writeFileSync(
	path.join(__dirname, '..', 'l10n', 'zh_CN.json'),
	JSON.stringify({ translations: catalogue, pluralForm: 'nplurals=1; plural=0;' }, null, 2) + '\n',
)
fs.writeFileSync(
	path.join(__dirname, '..', 'l10n', 'zh_CN.js'),
	`OC.L10N.register(\n\t"todofolder",\n\t${JSON.stringify(catalogue, null, 2).replace(/\n/g, '\n\t')},\n\t"nplurals=1; plural=0;"\n);\n`,
)
console.log(`catalogue entries: ${Object.keys(catalogue).length}`)
