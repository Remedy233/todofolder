// Rebuild l10n/zh_CN.{json,js} from the three migration dictionaries plus what the code uses.
// The dictionaries are the source of truth; the code decides which keys are actually shipped.
const fs = require('fs')
const path = require('path')

const ROOT = path.join(__dirname, '..')
const pairRe = /'((?:[^'\\]|\\.)*)'\s*:\s*'((?:[^'\\]|\\.)*)'/g
const unescape = (s) => s.replace(/\\'/g, "'")

const zhToEn = new Map()
const enToZh = new Map()
const read = (file) => fs.readFileSync(path.join(__dirname, file), 'utf8')

for (const file of ['i18n-en-source.cjs', 'i18n-php-l10n.cjs']) {
	const text = read(file)
	for (const m of text.matchAll(pairRe)) {
		const zh = unescape(m[1])
		const en = unescape(m[2])
		zhToEn.set(zh, en)
		enToZh.set(en, zh)
	}
}
for (const m of read('i18n-finish.cjs').matchAll(pairRe)) {
	enToZh.set(unescape(m[1]), unescape(m[2]))
}

// keys in use
const files = []
const walk = (dir) =>
	fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
		const full = path.join(dir, e.name)
		if (e.isDirectory()) return walk(full)
		return /\.(php|vue|js)$/.test(e.name) ? [full] : []
	})
files.push(...walk(path.join(ROOT, 'lib')), ...walk(path.join(ROOT, 'src')))

const wanted = new Set()
for (const file of files) {
	const text = fs.readFileSync(file, 'utf8')
	for (const m of text.matchAll(/(?<!\w)t\(\s*'((?:[^'\\]|\\.)*)'/g)) wanted.add(unescape(m[1]))
	for (const m of text.matchAll(/\$this->l10n->t\('((?:[^'\\]|\\.)*)'/g)) wanted.add(unescape(m[1]))
}

const catalogue = {}
const missing = []
for (const key of [...wanted].sort()) {
	const zh = enToZh.get(key)
	if (zh) catalogue[key] = zh
	else missing.push(key)
}

fs.mkdirSync(path.join(ROOT, 'l10n'), { recursive: true })
const json = JSON.stringify({ translations: catalogue, pluralForm: 'nplurals=1; plural=0;' }, null, 2) + '\n'
const js = `OC.L10N.register(\n\t"todofolder",\n\t${JSON.stringify(catalogue, null, 2).replace(/\n/g, '\n\t')},\n\t"nplurals=1; plural=0;"\n);\n`

// Two spellings are needed: PHP and `Util::addTranslations` ask for zh_CN (the file name
// convention), while the JavaScript runtime resolves the active language as `zh-CN`
// (@nextcloud/l10n builds `<language>.json` from globalThis._nc_l10n_language).
for (const name of ['zh_CN', 'zh-CN']) {
	fs.writeFileSync(path.join(ROOT, 'l10n', `${name}.json`), json)
	fs.writeFileSync(path.join(ROOT, 'l10n', `${name}.js`), js)
}

console.log(`keys in use: ${wanted.size}, translated: ${Object.keys(catalogue).length}, missing: ${missing.length}`)
missing.forEach((m) => console.log('  MISSING ' + m))
