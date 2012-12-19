<?php

	if( !defined('__IN_SYMPHONY__') ) die('<h2>Error</h2><p>You cannot directly access this file</p>');



	require_once('lib/class.LSE.php');



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
					'delegate' => 'AppendPageAlert',
					'callback' => 'dAppendPageAlert'
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

			$this->_section = LSE::getSection($callback['context']['section_handle']);
			if( is_null($this->_section) ){
				$this->_enabled = false;
				return false;
			}

			$this->_max = LSE::getMaxEntries($this->_section);
			$this->_total = LSE::getTotalEntries($this->_section);
			$entry_id = LSE::getLastEntryID($this->_section);
			$params = $this->_fetchUrlParams();
			$section_handle = $this->_section->get('handle');


			/* Manage redirects */

			// index page
			if( $callback['context']['page'] === 'index' ){

				// emulate Static section
				if( $this->_max === 1 ){

					// entry exists, proceed to edit page
					if( $this->_total > 0 && is_int($entry_id) ){
						$this->_redirect(SYMPHONY_URL."/publish/{$section_handle}/edit/{$entry_id}/{$params}");
					}

					// in no entries, proceed to new page
					else{
						$this->_redirect(SYMPHONY_URL."/publish/{$section_handle}/new/{$params}");
					}
				}
			}

			// new page
			elseif( $callback['context']['page'] === 'new' ){

				// only if there is a limit
				if( $this->_max > 0 ){

					// if limit exceeded, proceed to index page
					if( $this->_total >= $this->_max ){
						$this->_redirect(SYMPHONY_URL."/publish/{$section_handle}/{$params}");
					}
				}
			}
		}

		public function dAppendPageAlert(){
			$callback = Administration::instance()->getPageCallback();

			// manipulate success message
			if( in_array($callback['context']['page'], array('new', 'edit')) ){
				$flag_create = false;
				$flag_all = false;

				// if entry was created or saved
				if( isset($callback['context']['flag']) ){

					// if there is a limit
					if( $this->_max > 0 ){
						$flag_create = true;

						// if not static section
						if( $this->_max > 1 ){
							$flag_all = true;
						}
					}
				}

				// if the status message must be changed
				if( $flag_create || $flag_all ){
					$alerts = Administration::instance()->Page->Alert;

					// remove old message
					foreach( $alerts as $key => $alert ){
						/** @var $alert Alert */
						if( $alert->type === Alert::SUCCESS ){
							unset($alerts[$key]);
						}
					}

					$msg_create = '';
					$msg_all = '';

					// create / update message
					if( $flag_create === true ){
						switch($callback['context']['flag']){
							case 'saved':
								$msg_create = __('Entry updated at %s.', array(Widget::Time('now')->generate()));
								break;

							case 'created':
								$msg_create = __('Entry created at %s.', array(Widget::Time('now')->generate()));
								break;
						}
					}

					// view all message
					if( $flag_all === true ){
						$link = '/publish/'.$callback['context']['section_handle'] . '/';

						$msg_all = ' <a href="' . SYMPHONY_URL . $link . '" accesskey="a">'
							. __('View all Entries')
							. '</a>';
					}

					// append alert
					$alerts[] = new Alert($msg_create.$msg_all, Alert::SUCCESS);

					// replace Alerts
					Administration::instance()->Page->Alert = $alerts;
				}
			}
		}

		public function dAdminPagePreGenerate($context){
			if( !$this->_enabled ) return false;

			$callback = Administration::instance()->getPageCallback();

			// index page
			if( $callback['context']['page'] === 'index' ){

				/* Create button */

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

				$context['oPage']->Contents->prependChild(new XMLElement('p', $feedback, array('style' => 'margin: 10px 0 0 18px;')));
			}

			// new/edit page
			elseif( in_array($callback['context']['page'], array('new', 'edit')) ){

				// replace breadcrumbs (emulate static section)
				if( $this->_max === 1 ){
					$breadcrumbs = $context['oPage']->Context->getChild(0);

					$children_count = $breadcrumbs->getNumberOfChildren();

					for( $i=$children_count-1; $i>=0; $i-- )
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

		private function _redirect($url){
			header('HTTP/1.1 303 See Other');
			redirect($url);
		}

	}
