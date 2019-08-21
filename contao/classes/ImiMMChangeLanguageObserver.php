<?php

namespace iMi\MMChangeLanguage;


class ImiMMChangeLanguageObserver
{

    /**
     * Detect the attribute name for the auto_item parameter which is used in the filter
     *
     * @param $filterId Filter ID
     * @return bool/string
     */
    protected function detectFilterAttribute($filterId) {
        $serviceContainer = $GLOBALS['container']['metamodels-service-container'];

        $filterCollection = $serviceContainer
            ->getFilterFactory()
            ->createCollection($filterId);

        // find out the attribute name for the auto_item parameter (if used)
        $parameters = $filterCollection->getParameters();
        $attributes = $filterCollection->getReferencedAttributes();
        $autoItemIndex = array_search('auto_item', $parameters);
        if ($autoItemIndex !== false) {
            $attributeName = $attributes[$autoItemIndex];
            return $attributeName;
        }

        return false;
    }

    /**
     * @return \MetaModels\IFactory
     */
    protected function getMMFactory()
    {

        $container = $GLOBALS['container']['metamodels-service-container'];
        $factory = $container->getFactory();
        return $factory;
    }

    /**
     * Detect meta models which are used in the current page
     * - via layout modules
     * - via content elements
     *
     * @return array metamodel name => attribute name
     */
    protected function getCurrentMetamodels() {
        global $objPage;

        $curModel = array();
        $factory = $this->getMMFactory();

        $layout = \LayoutModel::findByPk($objPage->layout);
        $modules = unserialize($layout->modules);

        foreach ($modules as $module) {
            $objModule = ( \ModuleModel::findByPk($module['mod'] ));
            if ($objModule->metamodel_layout) {
                $modelName = $factory->translateIdToMetaModelName($objModule->metamodel);
                $filterAttribute = $this->detectFilterAttribute($objModule->metamodel_filtering);
                if ($filterAttribute !== false) {
                    $curModel[$modelName] = $filterAttribute;
                }
            };
        }

        $objArticles = \ArticleModel::findPublishedByPidAndColumn($objPage->id, 'main');
        if ($objArticles) {
            foreach($objArticles as $article ) {
                $contents = \ContentModel::findPublishedByPidAndTable($article->id, 'tl_article');
                if ($contents) {
                    foreach( $contents as $content ) {
                        if ($content->type == 'module') { // resolve insert module
                            $objModule = ( \ModuleModel::findByPk($content->module));
                            if ($objModule->metamodel_layout) {
                                $modelName = $factory->translateIdToMetaModelName($objModule->metamodel);
                                $filterAttribute = $this->detectFilterAttribute($objModule->metamodel_filtering);
                                if ($filterAttribute !== false) {
                                    $curModel[$modelName] = $filterAttribute;
                                }
                            };
                            continue;
                        }

                        $modelName = $factory->translateIdToMetaModelName($content->metamodel);
                        if (!$modelName) {
                            continue;
                        }

                        $filterAttribute = $this->detectFilterAttribute($content->metamodel_filtering);
                        if ($filterAttribute !== false) {
                            $curModel[$modelName] = $filterAttribute;
                        }
                    };
                }
            }
        };

        return $curModel;
    }


	/**
	 * For the new changelanguage v3
	 *
	 * @param \Terminal42\ChangeLanguage\Event\ChangelanguageNavigationEvent $event
	 */
	public function translateMMUrlsV3(
		\Terminal42\ChangeLanguage\Event\ChangelanguageNavigationEvent $event
	) {
		// The target root page for current event
		$targetRoot = $event->getNavigationItem()->getRootPage();
		$targetLanguage   = $targetRoot->language; // The target language

        $factory = $this->getMMFactory();

        $currentMetaModels = $this->getCurrentMetamodels();

        if (!$currentMetaModels) {
            return;
        }

		$alias = \Input::get('auto_item');
		if ($alias == null) {
			return;
		}

		if ($targetLanguage == $GLOBALS['TL_LANGUAGE']) {
            // add missing url parameter
		    $event->getUrlParameterBag()->setUrlAttribute('items', $alias);
		    return;
		}

		// allow overwriting of the auto-detected definition
		if (isset($GLOBALS['TL_CONFIG']['mm_changelanguage'])) {
			$currentMetaModels = array_merge($currentMetaModels, $GLOBALS['TL_CONFIG']['mm_changelanguage']);
		}
		foreach($currentMetaModels as $modelName=>$attributeName) {
			$metaModel = $factory->getMetaModel($modelName);
			$attribute = $metaModel->getAttribute($attributeName); // your attribute name here.
			// Only for safety here - You most definitely know that your alias is translated. ;)
			if (!in_array('MetaModels\Attribute\ITranslated', class_implements($attribute))) {
				continue;
			}

			$arrLanguages = array($GLOBALS['TL_LANGUAGE']);

			// we need this for fallback processing
			// see also https://github.com/MetaModels/core/issues/1092 (all_langs does not help here)
			$strFallbackLanguage = $metaModel->getFallbackLanguage();
			array_unshift($arrLanguages, $strFallbackLanguage);

			// find the current language's metamodel (current language, with fallback if it is "virtual" i.e. date not yet copied)
			$ids = $attribute->searchForInLanguages($alias, $arrLanguages);
			if (count($ids) < 1) {
				continue;
			}

            // check for a published attribute
			if ($metaModel->hasAttribute('published')) {
                $published = $metaModel->getAttribute('published');

                $publishedData = array_shift($published->getTranslatedDataFor($ids, $targetLanguage));
                if ($publishedData == null) {
                    $publishedData = array_shift($published->getTranslatedDataFor($ids, $strFallbackLanguage));
                }

                if (!is_null($publishedData)) {
                    // found a published attribute

                    if (!$publishedData['value']) {
                        // the item is not published in this language
                        // fallback to the parent page
                        $targetPage = $event->getNavigationItem()->getTargetPage();
                        $targetPage = \Contao\PageModel::findByPk($targetPage->pid);
                        $event->getNavigationItem()->setTargetPage($targetPage, false);
                        return;
                    }
                }
            }

			$attributeData = array_shift($attribute->getTranslatedDataFor($ids, $targetLanguage));
			if ($attributeData == null) {
				$attributeData = array_shift($attribute->getTranslatedDataFor($ids, $strFallbackLanguage));
			}

			if (is_null($attributeData)) {
				$event->skipInNavigation();
				return;
			} else {
				$value = $attributeData['value'];
				// Override URL parameter now.
				$event->getUrlParameterBag()->setUrlAttribute('items', $value);
				return;
			}
			return;
		}
	}

    /**
     * Hook callback for changelanguage extension to support language switching on product reader page
     * Changelanguage v2
     * @deprecated
     */
    public function translateMMUrlsV2($arrParams, $strLanguage, $arrRootPage, &$addToNavigation)
    {
        // Remove index.php fragment from uri and drop query parameters as we are not interested in those.
        list($fullUri) = explode('?', str_replace('index.php/', '', \Environment::get('request')), 2);
        // Handle remaining arguments, and do that only if there are exactly two.
        $uri = explode('/', $fullUri);

        // Remove language code
        if (\Config::get('addLanguageToUrl')) {
            array_shift($uri);
        }

        $alias = \Input::get('auto_item');
        if ($alias == null) {
            return $arrParams;
        }

        $currentMetaModels = $this->getCurrentMetamodels();

        // allow overwriting of the auto-detected definition
        if (isset($GLOBALS['TL_CONFIG']['mm_changelanguage'])) {
            $currentMetaModels = array_merge($currentMetaModels, $GLOBALS['TL_CONFIG']['mm_changelanguage']);
        }
        foreach($currentMetaModels as $modelName=>$attributeName) {
            $metaModel = \MetaModels\Factory::byTableName($modelName);
            $attribute = $metaModel->getAttribute($attributeName); // your attribute name here.
            // Only for safety here - You most definitely know that your alias is translated. ;)
            if (!in_array('MetaModels\Attribute\ITranslated', class_implements($attribute))) {
                continue;
            }
            $ids = $attribute->searchForInLanguages($alias, array($GLOBALS['TL_LANGUAGE']));
            if (count($ids) < 1) {
                continue;;
            }
            $attributeData = array_shift($attribute->getTranslatedDataFor($ids, $strLanguage));

            if (is_null($attributeData)) {
                // this requires https://github.com/terminal42/contao-changelanguage/pull/48
                $addToNavigation = false;
            } else {
                $value = $attributeData['value'];
                // Override URL parameter now.
                $GLOBALS['TL_CONFIG']['useAutoItem'] = $uri[0];
                $arrParams['url'] = array($value);
            }
            return $arrParams;
        }

        return $arrParams;
    }
}
