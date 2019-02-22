<?php
/**
 * @package      Joomla.Plugin
 * @subpackage   Content.Jteasylaw
 *
 * @author       Guido De Gobbis <support@joomtools.de>
 * @copyright    2018 JoomTools.de - All rights reserved.
 * @license      GNU General Public License version 3 or later
**/

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * Class plgContentJteasylaw
 *
 * Insert and cache law information from easyrechtssicher.de
 *
 * @package     Joomla.Plugin
 * @subpackage  Content.jteasylaw
 * @since       1.0.0
 */
class PlgContentJteasylaw extends JPlugin
{
	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var     boolean
	 * @since   1.0.0
	 */
	protected $autoloadLanguage = true;
	/**
	 * Global application object
	 *
	 * @var     JApplication
	 * @since   1.0.0
	 */
	protected $app;
	/**
	 * Supported languages from Easyrechtssicher
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	private $supportedLangguages = [
		'de',
		'en',
	];
	/**
	 * Collection point for error messages
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	private $message = [];
	/**
	 * Document calls
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	private $documentCalls = [
		'dse' => 'PLG_CONTENT_JTEASYLAW_CALL_DSE_LABEL',
		//'imp' => 'PLG_CONTENT_JTEASYLAW_CALL_IMP_LABEL',
	];

	/**
	 * onContentPrepare
	 *
	 * @param   string   $context  The context of the content being passed to the plugin.
	 * @param   object   $article  The article object.  Note $article->text is also available
	 * @param   mixed    $params   The article params
	 * @param   integer  $page     The 'page' number
	 *
	 * @return   void
	 * @since    1.0.0
	 */
	public function onContentPrepare($context, &$article, &$params, $page = 0)
	{
		// Don't run in administration Panel or when the content is being indexed
		if (strpos($article->text, '{jteasylaw ') === false
			|| $this->app->isClient('administrator') === true
			|| $context == 'com_finder.indexer'
			|| $this->app->input->getCmd('layout') == 'edit')
		{
			return;
		}

		$debug = filter_var(
			$this->params->get('debug', 0),
			FILTER_VALIDATE_BOOLEAN
		);

		$useCss = filter_var(
			$this->params->get('usecss', 1),
			FILTER_VALIDATE_BOOLEAN
		);

		$cachePath  = JPATH_CACHE . '/plg_content_jteasylaw';
		$cacheTime  = (int) $this->params->get('cachetime', 1440) * 60;
		$cacheOnOff = filter_var(
			$this->params->get('cache', 1),
			FILTER_VALIDATE_BOOLEAN
		);

		$methode     = $this->params->get('methode', 'html');
		$licenseKey  = $this->params->get('licensekey', '');
		$defaultLang = $this->params->get('language', 'de');
		$activeLang  = strtolower(substr(Factory::getLanguage()->getTag(), 0, 2));
		$language    = in_array($activeLang, $this->supportedLangguages) ? $activeLang : $defaultLang;
		$domain      = Uri::getInstance()->getHost();

		if (empty($licenseKey))
		{
			$this->app->enqueueMessage(
				Text::_('PLG_CONTENT_JTEASYLAW_WARNING_NO_LICENSEKEY'),
				'error'
			);

			return;
		}

		if(strlen($licenseKey) != 25)
		{
			$this->app->enqueueMessage(
				Text::_('PLG_CONTENT_JTEASYLAW_WRONG_LICENSEKEY'),
				'error'
			);

			return;
		}

		if ($cacheTime < 3600)
		{
			$cacheTime = 3600;
		}

		if ($cacheOnOff === false)
		{
			$cacheTime = 0;
		}

		$plgCalls = $this->getPlgCalls($article->text);

		foreach ($plgCalls[0] as $key => $plgCall)
		{
			$callType = trim(strtolower($plgCalls[1][$key][0]));
			$fileName = $callType . '.html';

			if (!empty($plgCalls[1][$key][1]))
			{
				// Get preferred language from plugin call
				$callLang = strtolower($plgCalls[1][$key][1]);

				// Validate if language is supported
				$supportedLangguages = in_array($callLang, $this->supportedLangguages) ? true : false;

				// Set site language or default language if preferred language is not supported
				$language = $supportedLangguages ? $callLang : $language;

				// Define error message if language is not supported
				if ($supportedLangguages === false)
				{
					$this->message['warning'][] = Text::sprintf(
						'PLG_CONTENT_JTEASYLAW_WARNING_LANGUAGE',
						$callLang,
						$language
					);
				}
			}

			$cacheFile        = $cachePath . '/' . $language . '/' . $fileName;
			$easylawServerUrl = 'https://easyrechtssicher.de/api/download/'
				. $callType . '/'
				. $licenseKey . '/'
				. $language . '/'
				. $domain . '.'
				. $methode;

			if (!Folder::exists(dirname($cacheFile)))
			{
				Folder::create(dirname($cacheFile));
			}

			if ($useCacheFile = File::exists($cacheFile))
			{
				$useCacheFile = $this->getFileTime($cacheFile, $cacheTime);
			}

			if($useCacheFile === false)
			{
				if ($methode == 'html')
				{
					$buffer = $this->getHtml($cacheFile, $easylawServerUrl);
				}
				else
				{
					$buffer = $this->getJson($cacheFile, $easylawServerUrl, $language);
				}
			}
			else
			{
				$buffer = $this->getBuffer($cacheFile);
			}

			$article->text = str_replace($plgCall, $buffer, $article->text);
		}

		if ($methode == 'json' && $useCss)
		{
			$css = $this->params->get('css');

			Factory::getDocument()->addStyleDeclaration($css);
		}


		if ($debug && !empty($this->message))
		{
			foreach ($this->message as $type => $msgs)
			{
				if ($type == 'error')
				{
					$msgs[] = Text::_('PLG_CONTENT_JTEASYLAW_ERROR_CHECKLIST');
				}

				$msg = implode('<br />', $msgs);
				$this->app->enqueueMessage($msg, $type);
			}
		}
	}

	/**
	 * Find all plugin call's in $text and return them as array
	 *
	 * @param   string  $text  Text with plugin call's
	 *
	 * @return   array  All matches found in $text
	 * @since    1.0.0
	 */
	private function getPlgCalls($text)
	{
		$regex = '@(<(\w*+)[^>]*>|){jteasylaw\s(.*)}(</\\2>|)@siU';
		$p1    = preg_match_all($regex, $text, $matches);

		if ($p1)
		{
			// Exclude <code/> and <pre/> matches
			$code = array_keys($matches[1], '<code>');
			$pre  = array_keys($matches[1], '<pre>');

			if (!empty($code) || !empty($pre))
			{
				array_walk($matches,
					function (&$array, $key, $tags) {
						foreach ($tags as $tag)
						{
							if ($tag !== null && $tag !== false)
							{
								unset($array[$tag]);
							}
						}
					}, array_merge($code, $pre)
				);
			}

			$options = [];

			foreach ($matches[0] as $key => $value)
			{
				$options[$key] = explode(',', $matches[3][$key]);
			}

			return array(
				$matches[0],
				$options,
			);
		}

		return array();
	}

	/**
	 * Check to see if the cache file is up to date
	 *
	 * @param   string  $file       Filename with absolute path
	 * @param   int     $cacheTime  Cachetime setup in params
	 *
	 * @return   bool  true if cached file is up to date
	 * @since    1.0.0
	 */
	private function getFileTime($file, $cacheTime)
	{
		$time      = time();
		$fileTime  = filemtime($file);

		$control = $time - $fileTime;

		if ($control >= $cacheTime)
		{
			return false;
		}

		return true;
	}

	/**
	 * Load HTML file from Server or get cached file
	 *
	 * @param   string  $cacheFile         Filename with absolute path
	 * @param   string  $easylawServerUrl  EasyLaw Server-URL for API-Call
	 *
	 * @return   bool  true if buffer is set
	 * @since    1.0.0
	 */
	private function getHtml($cacheFile, $easylawServerUrl)
	{
		$http = JHttpFactory::getHttp();
		$data = $http->get($easylawServerUrl);

		if ($data->code >= 200 && $data->code < 400)
		{
			$error = !preg_match('@<body[^>]*>(.*?)<\/body>@is' ,$data->body, $matches);

			if ($error === false)
			{
				$cTag = $this->params->get('ctag', 'section');
				$html = '<' . $cTag . ' class="jteasylaw">';
				$html .= $matches[1];
				$html .= '</' . $cTag . '>';

				if (!empty($html))
				{
					$this->setBuffer($cacheFile, $html);

					return $html;
				}
			}
		}

		$fileName     = basename($cacheFile);
		$documentCall = File::stripExt($fileName);
		$documentCall = Text::_($this->documentCalls[$documentCall]);

		$this->message['error'][] = Text::sprintf(
			'PLG_CONTENT_JTEASYLAW_ERROR_NO_CACHE_SERVER',
			$documentCall,
			$data->code,
			'<br />' . $data->body
		);

		return $this->getBuffer($cacheFile);
	}

	/**
	 * Load JSON file from Server or get cached file
	 *
	 * @param   string  $cacheFile         Filename with absolute path
	 * @param   string  $easylawServerUrl  EasyLaw Server-URL for API-Call
	 * @param   string  $language          Language shortcode (de, en)
	 *
	 * @return   string
	 * @since    1.0.0
	 */
	private function getJson($cacheFile, $easylawServerUrl, $language)
	{
		$error   = false;
		$message = [];

		$http   = JHttpFactory::getHttp();
		$data   = $http->get($easylawServerUrl);
		$result = json_decode($data->body);

		if ($result === null)
		{
			$result = $data->body;
		}

		if (!is_object($result)
			|| (is_object($result) && empty($result->ok) && $result->ok !== 0))
		{
			$message[] = $result;
			$error     = true;
		}

		if (is_object($result) && $result->ok === 0)
		{
			$message[] = $result->errMsg;
			$error     = true;
		}

		if ($error === false)
		{
			$cTag = $this->params->get('ctag', 'section');
			$html = '<' . $cTag . ' class="jteasylaw">';
			$html .= $this->formatRules($result->rules);
			$html .= '</' . $cTag . '>';

			if (!empty($html))
			{
				$this->setBuffer($cacheFile, $html);

				return $html;
			}
		}

		$fileName     = basename($cacheFile);
		$documentCall = File::stripExt($fileName);

		if (!empty($this->documentCalls[$documentCall]))
		{
			$documentCall = Text::_($this->documentCalls[$documentCall]);
		}

		$this->message['error'][] = Text::sprintf(
			'PLG_CONTENT_JTEASYLAW_ERROR_NO_CACHE_SERVER',
			$documentCall,
			$data->code,
			implode('<br />', $message)
		);

		return $this->getBuffer($cacheFile);
	}

	/**
	 * Create HTML from rules
	 *
	 * @param   array  $rules  Array of rules from $this->getJason()
	 *
	 * @return   string
	 * @since    1.0.0
	 */
	private function formatRules(array $rules)
	{
		$html      = '';
		$hTag      = (int) $this->params->get('htag', '1');
		$container = $this->params->get('ctag', 'section');

		foreach ($rules as $rule)
		{
			$cTag  = ($rule->level > 2) ? 'div' : $container;
			$level = (int) $rule->level - 1;
			$level = ($level < 1) ? 1 : $level;
			$hTag  = $hTag + (int) $rule->level - 2;
			$hTag  = ($hTag < 1) ? 1 : $hTag;
			$hTag  = ($hTag > 6) ? 6 : $hTag;

			$html .= '<' . $cTag . ' id="' . $rule->name
				. '" class="' . $rule->name . ' level' . $level . '">';

			if (!empty($rule->header))
			{
				$html .= '<h' . $hTag . '>'
					. strip_tags($rule->header)
					. '</h' . $hTag . '>';
			}

			if (!empty($rule->content))
			{
				$html .= '<p>' . nl2br($rule->content) . '</p>';
			}

			if (!empty($rule->rules) && is_array($rule->rules))
			{
				$html .= $this->formatRules($rule->rules);
			}

			$html .= '</' . $cTag . '>';
		}

		return $html;
	}

	/**
	 * Get content from cache file
	 *
	 * @param   string  $cacheFile  Path to cache file
	 *
	 * @return   string
	 * @since    1.0.0
	 */
	private function getBuffer($cacheFile)
	{
		if (file_exists($cacheFile))
		{
			return @file_get_contents($cacheFile);
		}

		return '';
	}

	/**
	 * Write content to cache file
	 *
	 * @param   string  $cacheFile  Path to cachef ile
	 * @param   string  $html       Content to write to cache file
	 *
	 * @return   void
	 * @since    1.0.0
	 */
	private function setBuffer($cacheFile, $html)
	{
		JFile::delete($cacheFile);
		JFile::write($cacheFile, $html);
	}
}
