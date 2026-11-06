<?php
declare(strict_types=1);
namespace SportsHub\Core;
use SportsHub\Config\Database;
use SportsHub\Models\Records;
/** Query values are bound; sort/filter column names come only from the supplied static map. */
final class ListQuery {
    public static function run(string $baseSQL,array $params,array $query,array $fields,array $filters=[]):array {
        $allowed=['page','limit','search','sort','order',...array_keys($filters)];$errors=[];
        foreach($query as $key=>$value)if(!in_array($key,$allowed,true)||!is_scalar($value))$errors[$key]=['Unsupported query parameter.'];
        $integer=static function(mixed $value,int $min,int $max):bool{return (is_string($value)&&ctype_digit($value)||is_int($value))&&(float)$value>=$min&&(float)$value<=$max;};
        $page=$query['page']??1;$limit=$query['limit']??10;
        if(!$integer($page,1,1000000))$errors['page']=['Use a page between 1 and 1000000.'];
        if(!$integer($limit,1,100))$errors['limit']=['Use a limit between 1 and 100.'];
        $sort=$query['sort']??array_key_first($fields);$order=$query['order']??'asc';
        if(!is_string($sort)||!isset($fields[$sort]))$errors['sort']=['Choose a supported sort field.'];
        if(!in_array($order,['asc','desc'],true))$errors['order']=['Choose asc or desc.'];
        if(isset($query['search'])&&(!is_string($query['search'])||mb_strlen($query['search'])>200))$errors['search']=['Search must have at most 200 characters.'];
        foreach($filters as $key=>$enum){
            if(!isset($query[$key])||$query[$key]==='')continue;
            if(!is_string($query[$key])&&!is_int($query[$key])){$errors[$key]=['Invalid filter.'];continue;}
            if($enum!==null&&!in_array($query[$key],$enum,true))$errors[$key]=['Choose '.implode(', ',$enum).'.'];
            if(str_ends_with($key,'Id')&&!$integer($query[$key],1,2147483647))$errors[$key]=['Choose a positive integer identifier.'];
        }
        if($errors)throw new ApiException('Please check your list filters.',422,$errors);
        $where=[];$bindings=$params;
        if(($query['search']??'')!==''){
            $search=[];
            foreach(array_keys($fields) as $key){$search[]='CAST(`'.$key.'` AS CHAR) LIKE ? ESCAPE \'!\'';$bindings[]='%'.strtr($query['search'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
            $where[]='('.implode(' OR ',$search).')';
        }
        foreach($filters as $key=>$unused)if(isset($query[$key])&&$query[$key]!==''){$where[]='`'.$key.'`=?';$bindings[]=$query[$key];}
        $from=' FROM ('.$baseSQL.') AS listed'.($where?' WHERE '.implode(' AND ',$where):'');
        $total=(int)Database::execute('SELECT COUNT(*)'.$from,$bindings)->fetchColumn();
        $page=(int)$page;$limit=(int)$limit;
        $rows=Database::all('SELECT *'.$from.' ORDER BY `'.$sort.'` '.strtoupper($order).' LIMIT ? OFFSET ?',[...$bindings,$limit,($page-1)*$limit]);
        return ['data'=>array_map([Records::class,'normalize'],$rows),'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>$total,'totalPages'=>(int)ceil($total/$limit)]];
    }
}
