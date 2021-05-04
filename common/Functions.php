<?php

class Functions
{
    static function generateRandomString($length = 10)
    {
        //$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $characters = '0123456789';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    static function minutesToHumanity($mins)
    {
        $days = 0;
        if ($mins > 60 * 24) {
            $days = intdiv(intdiv($mins, 60), 24);
            $mins -= $days * 60 * 24;
        }
        if ($mins > 60) {
            $hours = intdiv($mins, 60);
            $mins -= $hours * 60;
        }

        $result = "";
        if ($days > 0) {
            $result = $days . "д " . $hours . "ч " . $mins . "м";
        } else if ($hours > 0) {
            $result = $hours . "ч " . $mins . "м";
        } else {
            $result = $mins . "м";
        }
        return $result;
    }

    /**
     * Соединяем строки только если они не превышают лимит. если превышают, то лимит устанавливаем в 0
     * @param $dest
     * @param $part
     * @param $limit
     * @return bool
     */
    static function tryConcatStrings(&$dest, $part, &$limit): bool
    {
        if (strlen($dest) + strlen($part) > $limit) {
            $limit = 0;
            return false;
        }
        $dest .= $part;
        return true;
    }
}