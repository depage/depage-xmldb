<?php

namespace Depage\XmlDb;

use Depage\XmlDb\Exceptions\XmlDbException;

class XpathParser
{
    // {{{ parseXpathElements
    public function parseXpathElements($xpath)
    {
        $pName = '(?:([^\/\[\]]*):)?([^\/\[\]]+)';
        $pCondition = '(?:\[(.*?)\])?';
        preg_match_all("/(\/+)$pName$pCondition/", $xpath, $levels, PREG_SET_ORDER);

        return $levels;
    }
    // }}}
    // {{{ parsePosition
    public function parsePosition($condition)
    {
        $positionArray = [];
        $matches = [];
        $pOperator = '(=|!=|<|>|<=|>=)';
        $pPosition = '([0-9]+)';

        if (preg_match("/^\s*(?:(?:position\(\))\s*$pOperator)?\s*$pPosition\s*$/", $condition, $matches)) {
            $positionArray[] = ($matches[1] == '') ? '=' : $matches[1];
            $positionArray[] = $matches[2];

            return $positionArray;
        }

        return false;
    }
    // }}}
    // {{{ parseAttributes
    public function parseAttributes($condition)
    {
        $cond_array = false;
        $temp_condition = $this->removeLiteralStrings($condition, $strings);

        if (preg_match('/^[\w\d@=: -<>\*]*$/', $temp_condition)) {
            /**
             * "//ns:name[@attr1] ..."
             * "//ns:name[@attr1 = 'string1'] ..."
             * "//ns:name[@attr1 = 'string1' and/or @attr2 = 'string2'] ..."
             */
            $cond_array = $this->getConditionAttributes($temp_condition, $strings);
        }

        return $cond_array;
    }
    // }}}
    // {{{ translateName
    public function translateName($ns, $name)
    {
        $colon = (strlen($ns) && strlen($name)) ? ':' : '';

        return str_replace('*', '%', "$ns$colon$name");
    }
    // }}}
    // {{{ getConditionOperator
    public function getConditionOperator($ns, $name)
    {
        if (str_contains($ns, "*") || str_contains($name, "*")) {
            return 'LIKE';
        } else {
            return '=';
        }
    }
    // }}}
    // {{{ getConditionAttributes
    public function getConditionAttributes($conditionString, $strings)
    {
        $conditionArray = [];

        $pAttr = '@(\w[\w\d:]*)';
        $pOperator = '(=|!=|<|>|<=|>=)';
        $pBool = '(and|or|AND|OR)';
        $pString = '\$(\d*)';

        preg_match_all("/$pBool?\s*$pAttr\s*(?:$pOperator\s*$pString)?/", $conditionString, $conditions, PREG_SET_ORDER);

        $first = true;
        foreach ($conditions as $condition) {
            $bool = $condition[1] ?? null;

            if ($first == $bool) {
                throw new XmlDbException('Invalid XPath syntax');
            }

            if ($first) {
                $first = false;
            };

            $conditionArray[] = [
                $condition[2],
                $condition[3] ?? null,
                (isset($condition[4]) && $condition[4] != '') ? $strings[$condition[4]] : null,
                $bool,
            ];
        }

        return $conditionArray;
    }
    // }}}
    // {{{ removeLiteralStrings
    public function removeLiteralStrings($text, &$strings)
    {
        $n = 0;
        $newText = '';
        $strings = [];

        $p = "/([^\"']*)|(?:\"([^\"]*)\"|'([^']*)')/";
        preg_match_all($p, $text, $parts);

        for ($i = 0; $i < count($parts[0]); $i++) {
            if ($parts[1][$i] == '' && ($parts[2][$i] != '' || $parts[3][$i] != '')) {
                $strings[$n] = $parts[2][$i] . $parts[3][$i];
                $newText .= "\$$n";
                $n++;
            } else {
                $newText .= $parts[1][$i];
            }
        }

        return $newText;
    }
    // }}}
    // {{{ cleanOperator
    public function cleanOperator($operator)
    {
        $cleaned = '';
        $operators = ['=', '!=', '<=', '>=', '<', '>', 'and', 'or'];

        if (in_array($operator, $operators)) {
            $cleaned = $operator;
        } else {
            throw new XmlDbException("Invalid XPath operator \"$operator\"");
        }

        return $cleaned;
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker et : */
