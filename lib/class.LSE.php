<?php



	require_once (TOOLKIT.'/class.entrymanager.php');
	require_once (TOOLKIT.'/class.sectionmanager.php');



	/**
	 * Offers some utility methods to access info regarding entry limits for a Section.
	 */
	Final Class LSE
	{

		/**
		 * Get a Section object from a handle or ID. If section is not found, returns null
		 *
		 * @param $section (optional) - section handle or ID. If null, section handle will be taken form $callback
		 *
		 * @return null|Section
		 */
		public static function getSection($section = null){
			if( $section instanceof Section ) return $section;

			if( $section === null ){
				$callback = Administration::instance()->getPageCallback();

				if( !isset($callback['context']['section_handle']) ) return null;

				$section = $callback['context']['section_handle'];
			}

			$section_id = is_numeric( $section ) ? $section : SectionManager::fetchIDFromHandle( $section );

			$s = SectionManager::fetch( $section_id );

			if( !$s instanceof Section ) return null;

			return $s;
		}

		/**
		 * Get the ID of the last entry. Last == sorting by the field from Section index
		 *
		 * @param $section
		 * @see LSE::getSection()
		 *
		 * @return int|null
		 */
		public static function getLastEntryID($section = null){
			if( ! $s = self::getSection( $section ) ) return null;

			EntryManager::setFetchSortingDirection( 'DESC' );
			$entry = EntryManager::fetch( null, $s->get( 'id' ), 1 );

			if( !is_array( $entry ) || empty($entry) ) return null;

			$entry = current( $entry );
			$id = (int) $entry->get( 'id' );

			return $id;
		}

		/**
		 * Get the total number of entries in this Section
		 *
		 * @param $section
		 * @see LSE::getSection()
		 *
		 * @return int
		 */
		public static function getTotalEntries($section = null){
			if( ! $s = self::getSection( $section ) ) return null;

			try{
				$count = Symphony::Database()->fetch( sprintf(
					"SELECT COUNT(*) FROM `tbl_entries` WHERE `section_id` = '%s'",
					$s->get( 'id' )
				) );

				if( is_array( $count ) ){
					$count = $count[0]['COUNT(*)'];
				}
			} catch( DatabaseException $dbe ){
				$count = 0;
			}

			return (int) $count;
		}

		/**
		 * Get the maximum number of entries for this Section.
		 *
		 * @param $section
		 * @see LSE::getSection()
		 *
		 * @return int
		 */
		public static function getMaxEntries($section = null){
			if( ! $s = self::getSection( $section ) ) return null;

			$count = (int) $s->get( 'max_entries' );

			return $count;
		}

	}
