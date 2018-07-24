<?php

require_once TOOLKIT.'/class.entrymanager.php';
require_once TOOLKIT.'/class.sectionmanager.php';

/**
 * Offers some utility methods to access info regarding entry limits for a Section.
 */
final class LSE
{

    /**
     * Get a Section object from a handle or ID. If section is not found, returns null
     *
     * @param $section (optional) - section handle or ID. If null, section handle will be taken form $callback
     *
     * @return null|Section
     */
    public static function getSection($section = null)
    {
        if ($section instanceof Section) {
            return $section;
        }

        if ($section === null) {
            $callback = Administration::instance()->getPageCallback();

            if (!isset($callback['context']['section_handle'])) {
                return null;
            }

            $section = $callback['context']['section_handle'];
        }

        $section_id = is_numeric($section) ? $section : SectionManager::fetchIDFromHandle($section);

        $s = (new SectionManager)
            ->select()
            ->section($section_id)
            ->execute()
            ->next();

        if (!$s instanceof Section) {
            return null;
        }

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
    public static function getLastEntryID($section = null)
    {
        if (!$s = self::getSection($section)) {
            return null;
        }

        return (new EntryManager)
            ->select([])
            ->projection(['e.id'])
            ->section($s->get('id'))
            ->sort('system:id', 'desc')
            ->limit(1)
            ->execute()
            ->integer('id');
    }

    /**
     * Get the total number of entries in this Section
     *
     * @param $section
     * @see LSE::getSection()
     *
     * @return int
     */
    public static function getTotalEntries($section = null)
    {
        if (!$s = self::getSection($section)) {
            return null;
        }
        $count = 0;

        try {
            $count = Symphony::Database()
                ->select()
                ->count()
                ->from('tbl_entries')
                ->where(['section_id' => $s->get('id')])
                ->execute()
                ->integer(0);
        } catch (DatabaseException $dbe) {
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
    public static function getMaxEntries($section = null)
    {
        if (!$s = self::getSection($section)) {
            return null;
        }

        return (int) $s->get('max_entries');
    }
}
