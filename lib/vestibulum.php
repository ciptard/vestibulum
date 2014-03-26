<?php
namespace om;
/**
 * @author Roman Ožana <ozana@omdesign.cz>
 */
class Vestibulum extends \stdClass {

	/** @var array */
	public $config;
	/** @var string */
	public $request;
	/** @var string */
	public $file;
	/** @var string */
	public $content;
	/** @var meta */
	public $meta;
	/** @var array */
	public $pages;
	/** @var string */
	public $home;

	public function __construct() {
		$this->config = $this->getConfig();

		foreach ($this->config['include'] as $file) @include_once $file; // intentionally @

		$this->request = $this->getRequest();
		$this->file = $this->getFile($this->request);
		$this->content = $this->getFileContent($this->file);
		$this->meta = $this->getMeta($this->content, $this->file);
		$this->pages = $this->getPages(dirname($this->file));
		$this->home = $this->url();
	}

	/**
	 * Return configuration
	 *
	 * @return array
	 */
	protected function getConfig() {
		return array_replace_recursive(
			[
				'title' => 'Vestibulum',
				'twig' => [
					'cache' => false,
					'autoescape' => false,
					'debug' => false,
				],
				'src' => getcwd() . '/src/',
				'templates' => getcwd(),
				'author' => null,
				'skip' => ['404', 'index'],
				'include' => [
					getcwd() . '/functions.php'
				]
			],
			@include(getcwd() . '/config.php') // intentionally @
		);
	}

	/**
	 * Return filename from request
	 *
	 * @param $request
	 * @return string
	 */
	public function getFile($request) {
		$src = $this->config['src'];
		if (
			is_file($file = $src . $request . '.html') ||
			is_file($file = $src . $request . '.md') ||
			is_dir($src . $request) && is_file($file = $src . $request . '/index.html') ||
			is_dir($src . $request) && is_file($file = $src . $request . '/index.md')
		) {
			return realpath($file);
		} elseif (is_file($file = $src . '/404.html') || is_file($file = $src . '/404.md')) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
			return realpath($file);
		}
	}

	/**
	 * Return file content
	 *
	 * @param $file
	 * @return string
	 */
	public function getFileContent($file) {
		return ($file ? @file_get_contents($file) : '# 404' . PHP_EOL . 'Page not found'); // intentionally @
	}

	/**
	 * Return requested URL
	 *
	 * @return mixed
	 */
	public function getRequest() {
		$request = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
		return preg_replace(['#\?.*#', '#/?index.php#'], ['', ''], urldecode($request));
	}


	/**
	 * Extract title from md|html content
	 *
	 * @param $content
	 * @param $ext
	 * @return null|string
	 */
	protected function getTitle($content, $ext) {
		$patterns = [
			'html' => '/<h1[^>]*>([^<>]+)<\/h1>/isU',
			'md' => '/ *# *([^\n]+?) *#* *(?:\n+|$)/isU',
		];

		if (
			isset($patterns[$ext]) &&
			preg_match_all($patterns[$ext], $content, $matches, PREG_SET_ORDER)
		) {
			$first = reset($matches);
			return trim(end($first));
		}
	}

	/**
	 * Read metadata from file
	 *
	 * @param $content
	 * @param $file
	 * @param null $order
	 * @return object
	 */
	protected function getMeta($content, $file, $order = null) {
		$ext = pathinfo($file, PATHINFO_EXTENSION);
		$basename = basename($file, '.' . $ext);
		$title = $this->getTitle($content, $ext) ? : $basename;

		$headers = [
			'id' => md5($content . $file),
			'class' => $basename,
			'title' => $title,
			'order' => $order ? : $title,
			'description' => $this->getShort($content),
			'author' => $this->config['author'],
			'date' => is_file($file) ? filemtime($file) : time(),
			'type' => $ext,
			'template' => 'index.twig'
		];

		preg_match('/<!--(.*)-->/sU', $content, $matches);

		if ($matches && $ini = end($matches)) {
			$ini = parse_ini_string(str_replace(':', '=', $ini), false, INI_SCANNER_RAW);
			if ($ini === false) die('Invalid File metadata');
			$headers = array_merge($headers, $ini);
		}

		$headers['file'] = realpath($file);
		$headers['slug'] = str_replace(realpath($this->config['src']), '', realpath(dirname($file))) . '/' . $basename;

		return (object)$headers;
	}

	protected function getPages($path) {
		$files = (array)glob($path . '/*.{html,md}', GLOB_BRACE);

		$pages = [];
		foreach ($files as $id => $file) {
			$ext = pathinfo($file, PATHINFO_EXTENSION);
			if (in_array(basename($file, '.' . $ext), $this->config['skip'])) continue;
			$meta = $this->getMeta(file_get_contents($file), $file);
			$pages[realpath($file)] = $meta;
		}

		uasort(
			$pages, function ($a, $b) {
				if (is_numeric($a->order) && is_numeric($b->order)) {
					return $a->order > $b->order;
				}
				return strcmp($a->order, $b->order);
			}
		);

		return $pages;
	}

	/**
	 * @see https://gist.github.com/jbroadway/2836900
	 * @param $string
	 * @param int $length
	 * @return mixed
	 */
	protected function getShort($string, $length = 128) {
		$rules = array(
			'/(#+)(.*)/' => '\2', // headers
			'/\[([^\[]+)\]\(([^\)]+)\)/' => '\1', // links
			'/(\*\*|__)(.*?)\1/' => '\2', // bold
			'/(\*|_)(.*?)\1/' => '\2', // emphasis
			'/\~\~(.*?)\~\~/' => '\1', // del
			'/\:\"(.*?)\"\:/' => '\1', // quote
			'/`(.*?)`/' => '\1', // inline code
			'/<(.|\n)*?>/' => '', // strip tags
			'/\s+/' => ' ' // strip spaces
		);

		$description = preg_replace(array_keys($rules), array_values($rules), $string);
		if (preg_match('#^.{1,' . $length . '}(?=[\s\x00-/:-@\[-`{-~])#us', trim($description), $match)) {
			return reset($match);
		}
		return $description;
	}

	protected function render() {
		$loader = new \Twig_Loader_Filesystem($this->config['templates']);
		$twig = new \Twig_Environment($loader, $this->config['twig']);
		$twig->addExtension(new \Twig_Extension_Debug());

		// undefined filters callback
		$twig->registerUndefinedFilterCallback(
			function ($name) {
				return function_exists($name) ?
					new \Twig_SimpleFilter($name, function () use ($name) {
						return call_user_func_array($name, func_get_args());
					}, ['is_safe' => ['html']]) : false;
			}
		);

		$twig->addFunction('url', new \Twig_SimpleFunction('url', [$this, 'url']));

		// undefined functions callback
		$twig->registerUndefinedFunctionCallback(
			function ($name) {
				return function_exists($name) ?
					new \Twig_SimpleFunction($name, function () use ($name) {
						return call_user_func_array($name, func_get_args());
					}) : false;
			}
		);

		// FIXME find better way
		$this->content = str_replace('%url%', $this->url(), $this->content);
		if (pathinfo($this->file, PATHINFO_EXTENSION) === 'md') {
			$this->content = \Michelf\MarkdownExtra::defaultTransform($this->content);
		}

		return $twig->render($this->meta->template, (array)$this);
	}

	/**
	 * Return current URL
	 *
	 * @param null $url
	 * @return string
	 */
	public static function url($url = null) {
		return (isset($_SERVER['HTTPS']) && strcasecmp(
			$_SERVER['HTTPS'], 'off'
		) ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] :
			(isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '')) .
		($_SERVER["SERVER_PORT"] == '80' ? null : ':' . $_SERVER["SERVER_PORT"]) . '/' . ltrim(
			parse_url($url, PHP_URL_PATH), '/'
		);
	}

	/**
	 * Render string content
	 *
	 * @return string
	 */
	public function __toString() {
		try {
			return $this->render();
		} catch (\Exception $e) {
			return $e->getMessage();
		}
	}

}