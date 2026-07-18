# PageLike

PageLike 0.1.3 是面向 MediaWiki 1.45.1、PHP 8.2–8.3 和 MariaDB 10.11 的小型逐页点赞扩展。它只保存“哪个命名账户在何时点赞了哪个 `page_id`”，并提供状态、计数和滚动 7 天、30 天、全部排行榜。

## 安装

把本目录复制或符号链接为 `$IP/extensions/PageLike/`，然后在 `LocalSettings.php` 中加载：

```php
wfLoadExtension( 'PageLike' );
```

必须在访问点赞接口前执行 updater；MediaWiki 扩展不会在普通网页请求中自动建表：

```sh
php maintenance/run.php update --quick
```

完成后，主命名空间页面会自动显示默认按钮，普通命名账户可以立即点赞；匿名及临时账户只能查看计数。排行榜仍需显式开启。

本仓库开发环境可把源码保留在 `extensions-dev/PageLike/`，并设置 `MW_DEV_EXTENSIONS=PageLike`。MediaWiki 不会自行扫描 `extensions-dev/`。修改 `.env.local` 后应使用 `./bin/compose up -d --force-recreate mediawiki` 让常驻 Web/FPM 容器取得新环境变量；容器入口会自动运行 updater。单纯 `restart` 不会更新容器环境。

## 配置

基础点赞开箱即用；以下是内置默认值，无需复制到 `LocalSettings.php`：

```php
$wgPageLikeEnabled = true;
$wgPageLikeEnableWrites = true;
$wgPageLikeEnableRanking = false;
$wgPageLikeShowDefaultButton = true;
$wgPageLikeAllowedNamespaces = [ NS_MAIN ];

// 普通命名账户默认拥有该权限。
$wgGroupPermissions['user']['pagelike'] = true;
```

站点仍可在 `wfLoadExtension( 'PageLike' );` 之后覆盖这些值。例如，只允许管理员写入：

```php
$wgGroupPermissions['user']['pagelike'] = false;
$wgGroupPermissions['sysop']['pagelike'] = true;
```

扩展注册的 `pagelike` RateLimit 对普通命名账户为 30 次/60 秒。基础点赞在 `CACHE_NONE` 下仍能工作，但该标准限流不会可靠生效；公开或生产站点必须配置非 `CACHE_NONE` 主缓存。排行榜默认关闭，启用前还应确认缓存和聚合查询满足站点负载预算。扩展不会擅自改变全站缓存选型。

匿名账户和临时账户可以读取计数，但不能点赞。`PageLikeEnableWrites=false` 时状态 API 仍返回真实计数和当前命名账户的已有状态，只把 `canlike` 设为 `false`。

## 单页关闭

页面源码中加入任一标准 behavior switch：

```text
__NOPAGELIKE__
__关闭点赞__
```

该标记通过 ParserOutput/PageProps 工作，不扫描原始 wikitext。标记存在时，页面不输出挂载点、不允许写入、不进入任何排行榜；数据库中的旧点赞会保留，移除标记后重新生效。

MediaWiki 1.45 的 `MagicWordArray` 会把内部 ID 直接用作 PCRE 命名分组，因此内部 PageProps 键使用 PCRE 安全的 `pagelike_nopagelike`（下划线）；带连字符的内部键会令该目标版本解析失败。此实现细节不影响上述两个页面源码标记。

如果标记位于被转入的模板中，所有转入该模板的宿主页面都会关闭点赞。扩展部署前已经含有该字面量的页面需要 purge，使旧 Parser Cache 重新解析。

## Action API

所有含用户状态或读取权限过滤的响应都使用 private cache mode。跨域行为完全遵守 MediaWiki 核心 CORS 配置；扩展不会自行开放携带 Cookie 的跨域写入。

读取一个或多个页面的状态：

```text
GET api.php?action=query&prop=pagelikeinfo&pageids=123|456&format=json&formatversion=2
```

返回字段为 `enabled`、`liked`、`count`、`canlike`。批量请求会批量读取 PageProps、计数和用户状态。

设置明确状态（不是 toggle）：

```text
POST api.php
action=pagelike&pageid=123&set=1&token=<csrf>&format=json&formatversion=2
```

`set=1` 使用 insert-ignore，重复调用不会刷新点赞时间；`set=0` 幂等删除。写入响应的 `liked` 和 `count` 直接来自同一主库事务，调用方不应紧接着用副本 GET 覆盖它。

读取排行榜：

```text
GET api.php?action=query&list=pagelikerank&plrperiod=7d&plrlimit=10&format=json&formatversion=2
```

`plrperiod` 必须为 `7d`、`30d` 或 `all`；`plrlimit` 默认 10、最大 100。7 天和 30 天都是从缓存生成时刻向前滚动的固定 24 小时窗口，不是自然周或自然月。排行榜原始候选缓存 60 秒，因此允许约一分钟延迟；标题、页面属性和调用者读取权限不会缓存，每次请求都会重新检查。

## 前端方式一：站点自定义最简按钮

保持 `$wgPageLikeShowDefaultButton = false`。扩展仍输出中性挂载点，但不加载默认模块。以下代码可放在 `MediaWiki:Common.js`；关键点是先加载 `mediawiki.api`，并在 POST 成功后直接采用响应：

```js
mw.loader.using( 'mediawiki.api' ).then( function () {
	var api = new mw.Api();
	document.querySelectorAll( '.ext-pagelike' ).forEach( function ( root ) {
		var pageId = Number( root.dataset.pageId );
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.disabled = true;
		root.appendChild( button );

		api.get( {
			action: 'query', prop: 'pagelikeinfo', pageids: pageId, formatversion: 2
		} ).then( function ( response ) {
			var state = response.query.pages[0].pagelikeinfo;
			function render() {
				button.textContent = ( state.liked ? '取消点赞' : '点赞' ) + ' ' + state.count;
				button.disabled = !state.canlike;
			}
			button.addEventListener( 'click', function () {
				button.disabled = true;
				api.postWithToken( 'csrf', {
					action: 'pagelike', pageid: pageId, set: state.liked ? 0 : 1,
					formatversion: 2
				} ).then( function ( writeResponse ) {
					state = Object.assign( state, writeResponse.pagelike );
					render();
				} );
			} );
			render();
		} );
	} );
} );
```

## 前端方式二：扩展默认按钮

默认已经启用。若站点曾关闭它，可设置：

```php
$wgPageLikeShowDefaultButton = true;
```

默认 ResourceLoader package 只在实际输出挂载点的页面加载。它初始化时读取状态，写入时使用 `postWithToken( 'csrf' )`，并维持原按钮节点和键盘焦点。无 JavaScript 时，中性空挂载点不会显示为可见按钮。

默认按钮使用红色互动主题。用户成功点赞时，红心会先压缩再过冲弹开，并伴随一圈短促粒子；初始读取到已点赞状态时不会播放。动画完全由本地 CSS 实现，不加载图片或外部资源，并在 `prefers-reduced-motion: reduce` 下自动关闭。

稳定 DOM 根状态 class 为 `.is-liked`、`.is-pending`、`.is-error`。默认样式公开以下变量：

```css
--pagelike-color
--pagelike-active-color
--pagelike-background
--pagelike-hover-background
--pagelike-border-color
--pagelike-burst-color
--pagelike-radius
```

## 前端方式三：全站样式和动画

可在 `MediaWiki:Common.css` 覆盖变量而不修改扩展：

```css
.ext-pagelike {
	--pagelike-active-color: #ff4d78;
	--pagelike-background: color-mix(in srgb, currentcolor 8%, transparent);
	--pagelike-radius: 0.75rem;
}
```

成功点赞或取消后，默认模块恰好触发一次唯一稳定的 JavaScript hook：

```js
mw.hook( 'ext.pageLike.changed' ).add( function ( event ) {
	if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}
	event.root.animate(
		[ { transform: 'scale(1)' }, { transform: 'scale(1.04)' }, { transform: 'scale(1)' } ],
		{ duration: 240 }
	);
} );
```

事件对象只承诺 `root`、`pageId`、`liked`、`count`。默认样式和上例均尊重 `prefers-reduced-motion`。

## 页面生命周期与数据

点赞绑定 `page_id`，页面移动无需迁移。移动到不允许的命名空间后记录保留但暂不显示，移回后恢复。页面删除完成时扩展同步、幂等地清理该 `page_id`；恢复页面从零开始。排行榜查询始终连接现存 `page` 行，即使异常遗留数据也不会展示已删除页面。

表 `pagelike_like` 只含 `pll_page_id`、`pll_user_id`、`pll_liked_at`。用户 ID 和时间属于可关联个人数据；公共 API 不提供点赞用户列表，正常点赞也不写入 MediaWiki 公共日志。

## 维护

预览并分批清理已不存在页面或用户的记录：

```sh
php extensions/PageLike/maintenance/PrunePageLikes.php --dry-run --batch-size=500
php extensions/PageLike/maintenance/PrunePageLikes.php --batch-size=500
```

按数字用户 ID 处理数据删除请求：

```sh
php extensions/PageLike/maintenance/DeleteUserLikes.php --user-id=123 --dry-run --batch-size=500
php extensions/PageLike/maintenance/DeleteUserLikes.php --user-id=123 --batch-size=500
```

两个脚本都会输出进度，并在失败时以非零状态退出。扩展不会创建自动定时任务。

## 回滚与卸载

普通回滚依次关闭写入、排行榜和总开关，保留扩展与数据表。若扩展影响启动，可直接注释 `wfLoadExtension( 'PageLike' );`；紧急回滚中不要删除表。

扩展停用时不会自动删表。永久卸载前应先关闭功能和扩展、备份 `pagelike_like`，确认不再恢复后再由数据库管理员显式删除该表。

## 测试

生产形状的本地容器可直接运行 PageLike 真实 API 冒烟测试。它会登录本地编辑账户，对主命名空间页面点赞、取消并恢复原始状态；脚本会拒绝对非本地主机运行：

```sh
make pagelike-smoke-test
```

以下开发门禁需要 MediaWiki 源码树已安装完整 Composer `require-dev` 依赖；本仓库的精简运行镜像不包含这些工具：

```sh
./bin/compose exec -T -e MW_DEV_EXTENSIONS=PageLike mediawiki \
  php tests/phpunit/phpunit.php extensions-dev/PageLike/tests/phpunit/
./bin/compose exec -T mediawiki \
  vendor/bin/phpcs --standard=MediaWiki --extensions=php extensions-dev/PageLike
./bin/compose exec -T -e MW_INSTALL_PATH=/var/www/html mediawiki \
  vendor/bin/phan -d extensions-dev/PageLike -k .phan/config.php \
  --allow-polyfill-parser --no-progress-bar
./bin/compose exec -T mediawiki \
  php maintenance/run.php validateRegistrationFile extensions-dev/PageLike/extension.json
./bin/compose exec -T mediawiki \
  php maintenance/run.php generateSchemaSql --validate \
  --json extensions-dev/PageLike/sql/tables.json --type mysql
```

QUnit 需仅在本地测试配置中设置 `$wgEnableJavaScriptTest = true`，再访问 `Special:JavaScriptTest?component=PageLike`；不要在生产环境开启该入口。本镜像没有 Node/npm 浏览器 runner，因此 QUnit 仍需浏览器执行。

若扩展已复制到标准 `$IP/extensions/PageLike/` 且上游源码树安装了完整开发依赖，也可使用 MediaWiki 的 `composer test`、`composer phan`、`composer phpunit:entrypoint -- extensions/PageLike/tests/phpunit/` 和 `npm test`。集成测试必须使用隔离的 MariaDB 10.11 测试库，不得对生产库运行。还应人工覆盖实际生产皮肤与一个核心皮肤、匿名/普通命名账户/管理员、键盘焦点、屏幕阅读器状态和 reduced motion。
