<?php
declare(strict_types=1);

namespace app\utils;

class ArrayHelper
{
    /**
     * 多维数组键名深度 ASCII 排序 (通常用于开放平台 API 安全签名场景)
     */
    public static function sortKeysDeep(array $array): array
    {
        if (empty($array)) return $array;

        // 遇到索引数组直接递归子集，不打乱顺序
        if (array_is_list($array)) {
            foreach ($array as &$value) {
                if (is_array($value)) $value = self::sortKeysDeep($value);
            }
            return $array;
        }

        ksort($array, SORT_STRING);
        foreach ($array as &$value) {
            if (is_array($value)) $value = self::sortKeysDeep($value);
        }
        return $array;
    }

    /**
     * 扁平化数据转树状层级结构 (无限极分类通用算法，默认适配前端 Tree 组件所需 Key)
     */
    public static function listToTree(array $elements, string $parentIdField = 'parent_id', string $idField = 'id', int $parentId = 0, array &$visited = []): array
    {
        $branch = [];
        // 防死循环保护
        if (isset($visited[$parentId])) return $branch;
        $visited[$parentId] = true;

        foreach ($elements as $element) {
            if ($element[$parentIdField] == $parentId) {
                // 兼容 Ant Design / Naive UI 前端控件常用结构
                if (!isset($element['key'])) {
                    $element['key'] = $element[$idField];
                }

                $children = self::listToTree($elements, $parentIdField, $idField, $element[$idField], $visited);
                if ($children) {
                    $element['children'] = $children;
                }
                $branch[] = $element;
            }
        }
        return $branch;
    }
}