<?php

/**
 * Class DataRetriverHelper
 */
class DataRetriverHelper
{
    /**
     * @param array $PHIDs
     *
     * @return array
     */
    public static function getAuthorsByPHIDs(array $PHIDs): array
    {
        $out = [];
        if (!$authors = id(new PhabricatorUser())->loadAllWhere('phid in (%Ls)', $PHIDs)) {
            return $out;
        }

        foreach ($authors as $author) {
            $out[$author->getPHID()] = $author;
        }

        return $out;
    }
}