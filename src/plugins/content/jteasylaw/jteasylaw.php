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

use Joomla\CMS\Language\Text;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
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

		$debug      = (JDEBUG || $this->params->get('debug', '1') == '1') ? true : false;
		$cachePath  = JPATH_PLUGINS . '/content/jteasylaw/cache';
		$cacheOnOff = filter_var($this->params->get('cache', 1), FILTER_VALIDATE_BOOLEAN);
		$licenseKey = $this->params->get('licensekey', '');
		$methode    = $this->params->get('methode', 'html');
		$language   = $this->params->get('language', 'de');
		$cacheTime  = (int) $this->params->get('cachetime', 1440) * 60;
		// $domain = Uri::getInstance()->getHost();
		$domain = 'test.de';

		if (empty($licenseKey))
		{
			$this->app->enqueueMessage(Text::_('PLG_CONTENT_JTEASYLAW_WARNING_NO_LICENSEKEY'), 'error');

			return;
		}

		if ($cacheTime < 600)
		{
			$cacheTime = 600;
		}

		if ($cacheOnOff === false)
		{
			$cacheTime = 0;
		}

		$plgCalls = $this->getPlgCalls($article->text);

		foreach ($plgCalls[0] as $key => $plgCall)
		{
			$fileName         = strtolower($plgCalls[1][$key][0]) . '.html';
			$language         = !empty($plgCalls[1][$key][1]) ? strtolower($plgCalls[1][$key][1]) : $language;
			$cacheFile        = $cachePath . '/' . $language . '/' . $fileName;
			$easylawServerUrl = 'https://easyrechtssicher.de/api/download/dse/' . $licenseKey . '/' . $domain . '.' . $methode;
			$easylawServerUrl = 'http://localhost:8080/jtlawupdateserver/easy/' . strtolower($plgCalls[1][$key][0]) . '.' . $methode;

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
	protected function getPlgCalls($text)
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
	protected function getFileTime($file, $cacheTime)
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
	 * @return   bool  true if buffer is set else false
	 * @since    1.0.0
	 */
	protected function getHtml($cacheFile, $easylawServerUrl)
	{
		$error    = false;
		$fileName = basename($cacheFile);
		$documentCall = File::stripExt($fileName);
		$documentCall = Text::_($this->documentCalls[$documentCall]);

		$http = JHttpFactory::getHttp();
		$data = $http->get($easylawServerUrl);

		if ($data->code >= 200 && $data->code < 400)
		{
			preg_match('@<body[^>]*>(.*?)<\/body>@is' ,$data->body, $matches);

			$html = $matches[1];

			if (!empty($html))
			{
				$this->setBuffer($cacheFile, $html);

				return $html;
			}
		}
		else
		{
			$this->message['error'][] = Text::sprintf(
				'PLG_CONTENT_JTEASYLAW_ERROR_NO_CACHE_SERVER', $documentCall, $data->code, '<br />' . $data->body
			);
		}

		return $this->getBuffer($cacheFile);
	}

	/**
	 * Load HTML file from Server or get cached file
	 *
	 * @param   string  $cacheFile         Filename with absolute path
	 * @param   string  $easylawServerUrl  EasyLaw Server-URL for API-Call
	 * @param   string  $language          Language shortcode (de, en)
	 *
	 * @return   string
	 * @since    1.0.0
	 */
	protected function getJson($cacheFile, $easylawServerUrl, $language)
	{
		$error    = false;
		$message = [];
		$fileName = basename($cacheFile);
		$documentCall = File::stripExt($fileName);
		$documentCall = $this->documentCalls[$documentCall];
		$domain   = Uri::getInstance()->getHost();
		$domain   = 'test.de';

		$http = JHttpFactory::getHttp();
		$data = $http->get($easylawServerUrl);

		if ($data->code >= 200 && $data->code < 400)
		{
			$result = json_decode($data->body);


			if (empty($result->ok))
			{
				$message[] = $result;
				$error = true;
			}

			if ($result->ok === 0)
			{
				$message[] = $result->errMsg;
				$error = true;
			}

			/* TODO validate language
			if (strtolower($result->language) != $language && $error === false)
			{
				$error = true;
			} */

			/* TODO validate domain
			if ($result->domain != $domain && $error === false)
			{
				$error = true;
			} */

			if ($error === false)
			{
				$html = $this->formatRules($result->rules);

				if (!empty($html))
				{
					$this->setBuffer($cacheFile, $html);

					return $html;
				}
			}
		}
		else
		{
			$this->message['error'][] = Text::sprintf(
				'PLG_CONTENT_JTEASYLAW_ERROR_NO_CACHE_SERVER', $documentCall, $data->code, implode('<br />', $message)
			);
		}

		return $this->getBuffer($cacheFile);
	}

	private function formatRules(array $rules)
	{
		$html      = '';
		$hTag      = (int) $this->params->get('htag', '1');
		$container = 'div';

		foreach ($rules as $rule)
		{
			$level = $hTag + (int) $rule->level - 2;
			$level = ($level < 1) ? 1 : $level;
			$level = ($level > 6) ? 6 : $level;
			$html .= '<' . $container . ' id="' . $rule->name . '" class="' . $rule->name . ' level' . $level . '">';

			if (!empty($rule->header))
			{
				$html .= '<h' . $level . '>' . strip_tags($rule->header) . '</h' . $level . '>';
			}

			if (!empty($rule->content))
			{
				$html .= '<p>' . $rule->content . '</p>';
			}

			if (!empty($rule->rules) && is_array($rule->rules))
			{
				$html .= $this->formatRules($rule->rules);
			}

			$html .= '</' . $container . '>';
		}

		return $html;
	}

	/**
	 * Get content from cache
	 *
	 * @param   string  $cacheFile  Path to cachefile
	 *
	 * @return   string
	 * @since    1.0.0
	 */
	private function getBuffer($cacheFile)
	{
		return @file_get_contents($cacheFile);
	}

	/**
	 * Write content to cache
	 *
	 * @param   string  $cacheFile  Path to cachefile
	 * @param   string  $html       Content to write to cachefile
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
