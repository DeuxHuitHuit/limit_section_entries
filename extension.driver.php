<?php

	if( !defined('__IN_SYMPHONY__') ) die('<h2>Error</h2><p>You cannot directly access this file</p>');



	require_once(TOOLKIT.'/class.entrymanager.php');
	require_once(TOOLKIT.'/class.sectionmanager.php');



	Class Extension_Limit_Section_Entries extends Extension
	{
		const DB_TABLE = 'tbl_sections';

		/**
		 * Flag to know if installation has been attempted already
		 *
		 * @var bool
		 */
		private $_tried_installation = false;

		/**
		 * Maximum number of entries in section
		 *
		 * @var int
		 */
		private $_max = 0;

		/**
		 * Total number of entries in section
		 *
		 * @var int
		 */
		private $_total = 0;

		/**
		 * Knows if necessary execution conditions are met.
		 *
		 * @var bool
		 */
		private $_enabled = false;

		/**
		 * Current section
		 *
		 * @var Section
		 */
		private $_section = null;



		/*------------------------------------------------------------------------------------------------*/
		/*  Installation  */
		/*------------------------------------------------------------------------------------------------*/

		public function install(){
			try{
				Symphony::Database()->query(sprintf(
					"ALTER TABLE `%s` ADD `max_entries` INT(11) NOT NULL DEFAULT 0 AFTER `hidden`;",
					self::DB_TABLE
				));
			}
			catch( DatabaseException $dbe ){
				if( $this->_tried_installation === false ){
					$this->_tried_installation = true;

					$this->uninstall();

					return $this->install();
				}
				
				return false;
			}

			$this->_tried_installation = true;

			return true;
		}

		public function uninstall(){
			try{
				Symphony::Database()->query(sprintf(
					"ALTER TABLE `%s` DROP `max_entries`;",
					self::DB_TABLE
				));
			}
			catch( DatabaseException $dbe ){
			}

			return true;
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Delegates  */
		/*------------------------------------------------------------------------------------------------*/

		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/backend/',
					'delegate' => 'InitaliseAdminPageHead',
					'callback' => 'dInitaliseAdminPageHead'
				),
				array(
					'page' => '/backend/',
					'delegate' => 'AdminPagePreGenerate',
					'callback' => 'dAdminPagePreGenerate'
				),


				array(
					'page' => '/blueprints/sections/',
					'delegate' => 'AddSectionElements',
					'callback' => 'dAddSectionElements'
				),
				array(
					'page' => '/blueprints/sections/',
					'delegate' => 'SectionPreCreate',
					'callback' => 'dSaveSectionSettings'
				),
				array(
					'page' => '/blueprints/sections/',
					'delegate' => 'SectionPreEdit',
					'callback' => 'dSaveSectionSettings'
				)
			);
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Backend  */
		/*------------------------------------------------------------------------------------------------*/

		public function dInitaliseAdminPageHead(){
			$callback = Administration::instance()->getPageCallback();

			$this->_enabled = $this->_enable($callback);
			if( !$this->_enabled ) return false;

			$this->_section = $this->_fetchSection($callback);
			if( is_null($this->_section) ){
				$this->_enabled = false;
				return false;
			}

			$this->_max = $this->_fetchMaxEntries();
			$this->_total = $this->_fetchTotalEntries();
			$entry_id = $this->_fetchEntryID();
			$params = $this->_fetchUrlParams();
			$section_handle = $this->_section->get('handle');


			/* Manage redirects */

			// index page
			if( $callback['context']['page'] === 'index' )

				// emulate Static section
				if( $this->_max === 1 )

					// entry exists, proceed to edit page
					if( $this->_total > 0 && is_int($entry_id) )
						$this->_redirect(SYMPHONY_URL."/publish/{$section_handle}/edit/{$entry_id}/{$params}");

					// if no entries, proceed to new page
					else
						$this->_redirect(SYMPHONY_URL."/publish/{$section_handle}/new/{$params}");

			// new page
			elseif( $callback['context']['page'] === 'new' )

				// only if there is a limit
				if( $this->_max > 0 )

					// if limit exceeded, proceed to index page
					if( $this->_total >= $this->_max )
						$this->_redirect(SYMPHONY_URL."/publish/{$section_handle}/{$params}");

		}

		public function dAdminPagePreGenerate($context){
			if( !$this->_enabled ) return false;

			$callback = Administration::instance()->getPageCallback();

			// index page
			if( $callback['context']['page'] === 'index' ){

				/* Hide "Create New" button */

				if( $this->_max > 0 && $this->_total >= $this->_max ){
					$context['oPage']->Context->getChild(1)->removeChildAt(0);
				}


				/* Feedback message */

				$msg_total_entries = $this->_total === 1
					? __('There is %d entry', array($this->_total))
					: __('There are %d entries', array($this->_total));

				$msg_max_entries = '';
				$msg_create_more = '';
				if( $this->_max !== 0 ){
					$msg_max_entries =  __(' out of a maximum of ') . $this->_max;
					if( $this->_total >= $this->_max ){
						$msg_create_more = __("You can't create more entries.");
					}
					else{
						$diff = $this->_max - $this->_total;
						$msg_create_more = __("You can create %d more", array($diff));
						$msg_create_more .= ' ' . ($diff === 1 ? __('entry') : __('entries')) . '.';
					}
				}

				$feedback = $msg_total_entries.$msg_max_entries.'. '.$msg_create_more;

				$context['oPage']->Contents->prependChild(
					new XMLElement('p', $feedback, array('style' => 'margin: 10px 0 0 18px;'))
				);
			}

			// new/edit page
			elseif( in_array($callback['context']['page'], array('new', 'edit')) ){

				/* Replace breadcrumbs (emulate static section) */

				if( $this->_max === 1 ){
					$breadcrumbs = $context['oPage']->Context->getChild(0);

					for( $count=$breadcrumbs->getNumberOfChildren(), $i=$count-1; $i>=0; $i-- )
						$breadcrumbs->removeChildAt($i);

					$breadcrumbs->appendChild(new XMLElement('h2', $this->_section->get('name')));
				}
			}
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  Blueprints sections  */
		/*------------------------------------------------------------------------------------------------*/

		public function dAddSectionElements($context){
			$label = Widget::Label(__('Maximum entries'));
			$label->appendChild(Widget::Input("meta[max_entries]", $context['meta']['max_entries']));
			$label->appendChild(new XMLElement('p', __('Limit the maximum number of entries to this positive integer value. Let 0 or empty for unlimited.'), array('class' => 'help')));

			$context['form']->getChildByName('fieldset', 0)->appendChild($label);
		}

		public function dSaveSectionSettings($context){
			$max_entries = (int) $context['meta']['max_entries'];

			if( $max_entries < 0 ) $max_entries = 0;

			$context['meta']['max_entries'] = $max_entries;
		}



		/*------------------------------------------------------------------------------------------------*/
		/*  In-house  */
		/*------------------------------------------------------------------------------------------------*/

		/**
		 * Makes sure we're in necessary context.
		 *
		 * @param $callback
		 *
		 * @return bool
		 */
		private function _enable($callback){
			if( $callback['driver'] !== 'publish' ) return false;

			$page_modes = array('index', 'new', 'edit');
			if( !in_array($callback['context']['page'], $page_modes) ) return false;

			return true;
		}

		/**
		 * Retrieve the ID of last entry in current section.
		 *
		 * @return int|null
		 */
		private function _fetchEntryID(){
			EntryManager::setFetchSortingDirection('DESC');
			$entry = EntryManager::fetch(null, $this->_section->get('id'), 1);

			if( is_array($entry) && !empty($entry) ){
				$entry = current($entry);
				return (int)$entry->get('id');
			}

			return null;
		}

		/**
		 * Retrieve the maximum number of entries for current section.
		 *
		 * @return int
		 */
		private function _fetchMaxEntries(){
			$count = $this->_section->get('max_entries');

			return (int) $count;
		}

		/**
		 * Retrieve current section object. If the section is not found, returns null.
		 *
		 * @param $callback (optional) - page callback
		 *
		 * @return Section|null
		 */
		private function _fetchSection($callback = null){
			if( is_null($callback) )
				$callback = Administration::instance()->getPageCallback();

			if( !isset($callback['context']['section_handle']) ) return null;
			
			$section_id = (int) SectionManager::fetchIDFromHandle($callback['context']['section_handle']);

			$section = SectionManager::fetch($section_id);

			if( !$section instanceof Section ) return null;

			return $section;
		}

		/**
		 * Retrieve the number of entries in current section.
		 *
		 * @return int
		 */
		private function _fetchTotalEntries(){
			try{
				$count = Symphony::Database()->fetch(sprintf(
					"SELECT COUNT(*) FROM `%s` WHERE `section_id` = '%s'",
					'tbl_entries', $this->_section->get('id')
				));

				if( is_array($count) ){
					$count = $count[0]['COUNT(*)'];
				}
			}
			catch( DatabaseException $dbe ){
				$count = 0;
			}

			return (int) $count;
		}

		/**
		 * Prepare the query string for re-transmit.
		 *
		 * @return string
		 */
		private function _fetchUrlParams(){
			if( count($_GET) > 2 ){
				$params = "?";
			}

			foreach( $_GET as $key => $value ){
				if( in_array($key, array('symphony-page', 'mode')) ) continue;

				$params .= "{$key}={$value}";
				if( next($_GET) ){
					$params .= '&';
				}
			}

			return $params;
		}

		/**
		 * Cleaner redirect method.
		 *
		 * @param $url
		 */
		private function _redirect($url){
			header('HTTP/1.1 303 See Other');
			redirect($url);
		}

	}
