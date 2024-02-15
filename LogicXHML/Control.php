<?php

namespace LogicXHML;

use \Exception;

class Control{
    /**
     * @see Control::out()
     * @see Control::get()
     * @see Control::keys()
     * @see Control::count()
     * @see Control::fn_keys()
     * @see Control::fn_count()
     * @see Control::fn_exists()
     */
    const FN_SPECIAL=[
        '$.out'=>'out',
        '$.get'=>'get',
        '$.keys'=>'keys',
        '$.count'=>'count',
        '$.fn_keys'=>'fn_keys',
        '$.fn_count'=>'fn_count',
        '$.fn_exists'=>'fn_exists',
    ];
    /**
     * @var Scope
     */
    private $props;
    /**
     * @var callable[]
     */
    private $fn=[];
    private $strict=false;
    private $output;
    private $buffer;

    public static function fnSpecial_name(string $name){
        return self::FN_SPECIAL[$name]??null;
    }

    public function __construct(){
        $this->setProps(new Scope());
    }

    /**
     * Establece si la llamada de funciones genera un error al no encontrar la función
     * @param bool $strict
     * @return bool Valor anterior
     */
    public function setStrict(bool $strict=true): bool{
        $l=$this->strict;
        $this->strict=$strict;
        return $l;
    }

    /**
     * Indica si la llamada de funciones genera un error al no encontrar la función
     * @return bool
     */
    public function isStrict(): bool{
        return $this->strict;
    }

    public function start(){
        $this->output=fopen('php://temp', 'w+');
        $this->buffer=null;
    }

    /**
     * @param string|null $data
     * @return string|null
     * @throws Exception
     */
    public function out(?string $data){
        if($this->buffer!==null){
            $this->buffer.=$data;
            return $data;
        }
        if(!$this->output) return null;
        $w=fwrite($this->output, strval($data));
        if($w===false) throw new Exception('Output error');
        return $data;
    }

    public function saveToFile(string $filename){
        if(!$this->output) return null;
        if(fseek($this->output, 0)==-1) return false;
        if(!($dest=fopen($filename, 'w+'))) return false;
        $copy=stream_copy_to_stream($this->output, $dest);
        fclose($dest);
        return $copy;
    }

    public function saveToResource($dest){
        if(!$this->output) return null;
        if(fseek($this->output, 0)==-1) return false;
        if(!is_resource($dest)) return false;
        $copy=stream_copy_to_stream($this->output, $dest);
        return $copy;
    }

    public function saveToOutput(){
        if(!$this->output) return null;
        if(fseek($this->output, 0)==-1) return false;
        $copy=fpassthru($this->output);
        return $copy;
    }

    /**
     * @param callable[] $list
     * @return void
     */
    public function fn_bind(array $list){
        foreach($list AS $k=>$v){
            if($v!==null && !is_callable($v)) continue;
            $this->set_fn($k, $v);
        }
    }

    public function set_fn(string $name, ?callable $fn){
        if($fn===null){
            unset($this->fn[$name]);
        }
        else{
            $this->fn[$name]=$fn;
        }
    }

    /**
     * @param $name
     * @param array $params
     * @return null
     * @throws Exception
     */
    public function &fn($name, array $params=[]){
        $res=null;
        if(is_callable($fn=$this->fn_get($name))) $res=$fn(...$params)??null;
        elseif($this->strict) throw new Exception('Function not found: '.$name);
        return $res;
    }

    /**
     * @param $name
     * @return callable|null
     */
    public function fn_get($name){
        return $this->fn[$name]??null;
    }

    /**
     * @return int
     */
    public function fn_count(){
        return count($this->fn_keys());
    }

    /**
     * @return int[]|string[]
     */
    public function fn_keys(){
        return array_merge(array_keys(self::FN_SPECIAL), array_keys(Scope::FN_SPECIAL), array_keys($this->fn));
    }

    /**
     * @param $name
     * @return bool
     */
    public function fn_exists($name){
        return in_array($name, $this->fn_keys(), true);
    }

    /**
     * @param $name
     * @return mixed|null
     */
    public function get($name){
        return $this->props->$name;
    }

    /**
     * @return int
     */
    public function count(){
        return $this->props->count();
    }

    /**
     * @return int[]|string[]
     */
    public function keys(){
        return $this->props->keys();
    }

    /**
     * @param Scope $props
     * @return void
     */
    public function setProps(Scope $props): void{
        $this->props=$props;
    }

    /**
     * @return Scope
     */
    public function getProps(){
        return $this->props;
    }

    public function clear_fn(){
        $this->fn=[];
    }

    public function run(Scope &$S, callable $fn){
        $L=new Scope($S);
        $fn($L);
    }

    public function for(Scope &$S, $max, array $args, callable $fn){
        if(!is_numeric($max)) return false;
        $max=intval($max);
        if($max<=0) return false;
        $L=new Scope($S);
        $p=$args['position']??'position';
        unset($args);
        try{
            for($i=0; $i<$max; ++$i){
                $L->$p=$i;
                try{
                    $fn($L);
                }catch(LoopContinue $loop){
                }
            }
        }catch(LoopBreak $loop){
        }
        return true;
    }

    public function foreach(Scope &$S, $list, array $args, callable $fn){
        if(!is_iterable($list)) return false;
        $L=new Scope($S);
        $offset=$args['offset']??null;
        if($offset!==null) $offset=intval($offset);
        $limit=$args['limit']??null;
        if($limit!==null) $limit=intval($limit);
        if($offset!==null && $limit!==null) $limit+=$offset;
        $p=$args['position']??'position';
        $k=$args['key']??'key';
        $v=$args['value']??'value';
        unset($args);
        $i=0;
        try{
            foreach($list as $key=>$value){
                if($offset!==null && $i<$offset) continue;
                if($limit!==null && $i>$limit) break;
                $L->$p=$i;
                $L->$k=$key;
                $L->$v=$value;
                ++$i;
                try{
                    $fn($L);
                }catch(LoopContinue $loop){
                }
            }
        }catch(LoopBreak $loop){
        }
        return $i>0;
    }

    /**
     * @param Scope $S
     * @param callable $fn
     * @return string
     * @throws LoopBreak
     * @throws LoopContinue
     */
    public function buffer(Scope &$S, callable $fn){
        $L=new Scope($S);
        $old=$this->buffer;
        $this->buffer='';
        try{
            $fn($L);
        }catch(LoopBreak|LoopContinue $loop){
            $this->buffer=$old;
            throw $loop;
        }
        $buffer=$this->buffer;
        $this->buffer=$old;
        return $buffer;
    }

    public function __get(string $name){
        return $this->get(substr($name, 1));
    }

    public function __set(string $name, $value): void{
        $this->props->$name=$value;
    }

    /**
     * @return callable[]
     */
    public static function fn_plus(){
        return [
            // General
            'is_scalar'=>'is_scalar',
            'is_string'=>'is_string',
            'is_numeric'=>'is_numeric',
            'is_array'=>'is_array',
            'is_object'=>'is_object',
            'is_iterable'=>'is_iterable',
            // Math
            'mod'=>function($a, $b=0){
                if(!is_numeric($a)) return null;
                if($b==0 || !is_numeric($b)) return null;
                return $a%$b;
            },
            'div'=>function($a, $b=0){
                if(!is_numeric($a)) return null;
                if($b==0 || !is_numeric($b)) return null;
                return $a/$b;
            },
            'mul'=>function($a, $b=1){ return $a*$b; },
            'add'=>function($a, $b=1){ return $a+$b; },
            'sub'=>function($a, $b=1){ return $a-$b; },
            'round'=>'round',
            'floor'=>'floor',
            'ceil'=>'ceil',
            'nformat'=>function($a, $b=2){ return number_format($a, $b); },
            'max'=>'max',
            'min'=>'min',
            // Text
            'concat'=>function(...$s){
                return implode('', $s);
            },
            'html'=>function($a){
                $a=strval(is_array($a)?'Array':$a);
                return htmlentities($a, ENT_QUOTES, mb_detect_encoding($a, mb_detect_order(), true));
            },
            'trim'=>function($s){ return trim($s); },
            'ltrim'=>function($s){ return ltrim($s); },
            'rtrim'=>function($s){ return rtrim($s); },
            'len'=>function($a){
                return strlen(strval($a));
            },
            'substr'=>function($str, $offset, $len=null){
                return substr(strval($str), $offset, $len);
            },
            // Array
            'count'=>function($arr){ if(!is_countable($arr)) return null; return count($arr); },
            'array'=>function(...$arr){ return $arr; },
            'implode'=>function($arr, $s=','){
                if(!is_array($arr)) $arr=[];
                return implode($s, $arr);
            },
            'explode'=>function($a, $s=','){ return explode($s, strval($a)); },
            'in'=>function($a, $arr){ return is_array($arr) && in_array($a, $arr); },
            'merge'=>function(...$arr){
                foreach($arr as &$a){
                    if(!is_array($a)) $a=[];
                }
                return array_merge(...$arr);
            },
            // Date
            'time'=>'time',
            'now'=>function(){ return date(DATE_W3C); },
            'today'=>function(){ return date('Y-m-d'); },
        ];
    }
}
