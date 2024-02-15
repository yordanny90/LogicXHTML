<?php

namespace LogicXHML;

use \Exception;

class Converter{
    const SYMBOL=':';
    const NAME_PROP='/^\$\.([a-z]\w*)$/i';
    const INIT_PROP='/^(\$\.[a-z]\w*)(.*)$/i';
    const PREG_SCOPE='(?:[_RP]\.)?[a-z]\w*';
    const INIT_SCOPE='/^('.self::PREG_SCOPE.')(.*)$/i';
    const REGEX_SCOPE_ALL='/^([_RP])\.([a-z]\w*)$/i';
    const SCOPE_ROOT='R';
    const SCOPE_PARENT='P';
    const SCOPE_AUTO='_';
    const NAME_SIMPLE='/^([a-z]\w*)$/i';
    const INIT_NUMERIC='/^(-?\d+(?:\.\d*)?(?:e[-+]?\d+)?)(.*)$/i';
    const INIT_STRING='/^('.self::PREG_STRING.')(.*)$/';
    const CONSTANTES=[
        'true',
        'false',
        'null',
    ];
    const PREG_STRING='\'(?:\\\[\w\"\'\\\]|[^\\\'])*\'|\"(?:\\\[\w\"\'\\\]|[^\\\"])*\"';
    const REGEX_COMMENT='/^\/\//';
    const REGEX_CODE='/^(?:('.self::PREG_SCOPE.')\s*=\s*)([^\=].*)$/i';
    const REGEX_ACTION='/^(@[a-z]+)(\b.*)$/i';
    const PREG_TYPES='PRINT|DO|IF|ELSEIF|ELSE|FOR|FOREACH|ELSEFOR|BUFFER';
    const REGEX_PRINT='/^(.*)\:\{((?:.(?<!\}\:))+)\}\:(.*\s*)$/';
    const REGEX_BEGIN_DOC='/^\s*\<('.self::TYPE_MAIN.')(\s[^\>]*)?\>\s*$/';
    const REGEX_BEGIN_PROC='/^\s*\<'.self::SYMBOL.'('.self::PREG_TYPES.')(\s[^>]*)?\>(.*)$/';
    const REGEX_END_PROC='/^(.*)\<\/'.self::SYMBOL.'('.self::PREG_TYPES.')\s*\>\s*$/';
    const REGEX_END_PRINT='/^\s*\<\/'.self::SYMBOL.'PRINT\s*\>\s*$/';
    const REGEX_END_DO='/^\s*\<\/'.self::SYMBOL.'DO\s*\>\s*$/';
    const PROC_VALUE=':=';
    const INDEX_TYPES=[
        self::TYPE_MAIN,
        self::TYPE_DO,
        self::TYPE_PRINT,
        self::TYPE_IF,
        self::TYPE_ELSEIF,
        self::TYPE_ELSE,
        self::TYPE_FOR,
        self::TYPE_FOREACH,
        self::TYPE_ELSEFOR,
        self::TYPE_ELSEFOREACH,
        self::TYPE_BUFFER,
    ];
    private const TYPE_MAIN='LogicXHTML';
    private const TYPE_DO='DO';
    private const TYPE_PRINT='PRINT';
    private const TYPE_IF='IF';
    private const TYPE_ELSEIF='ELSEIF';
    private const TYPE_ELSE='ELSE';
    private const TYPE_FOR='FOR';
    private const TYPE_FOREACH='FOREACH';
    private const TYPE_ELSEFOR='ELSEFOR';
    private const TYPE_ELSEFOREACH='ELSEFOREACH';
    private const TYPE_BUFFER='BUFFER';
    private const VAR_CONTROL='$C';
    private const VAR_SCOPE='$S';

    protected $filename;
    protected $origin;
    protected $result;
    protected $success=false;
    protected $num_line=0;
    protected $lvl=[];
    protected $mode='';
    protected $nextElse=false;
    protected $useProps=[];
    protected $useFn=[];

    private function _restart(){
        $this->success=false;
        $this->num_line=0;
        $this->lvl=[];
        $this->mode='';
        $this->nextElse=false;
        $this->useProps=[];
        $this->useFn=[];
    }

    protected function __construct(string $filename, &$origin, &$result){
        $this->filename=$filename;
        $this->origin=&$origin;
        $this->result=&$result;
    }

    /**
     * @param $filename
     * @return false|static
     */
    public static function loadFile($filename, $gz=false){
        if($fnreal=realpath($filename)) $filename=$fnreal;
        if($gz){
            if(!($origin=gzopen($filename, 'rb'))) return false;
        }
        else{
            if(!($origin=fopen($filename, 'r'))) return false;
        }
        if(!($result=fopen('php://memory', 'w+'))) return false;
        $new=new static($filename, $origin, $result);
        return $new;
    }

    public static function loadString(string $content){
        if(!($origin=fopen('php://memory', 'w+'))) return false;
        if(fwrite($origin, $content)===false){
            fclose($origin);
            return false;
        }
        fseek($origin, 0);
        if(!($result=fopen('php://memory', 'w+'))) return false;
        $new=new static(':memory', $origin, $result);
        return $new;
    }

    public static function loadStream($resource){
        if(!is_resource($resource)) return false;
        if(!($result=fopen('php://memory', 'w+'))) return false;
        $new=new static(':memory', $resource, $result);
        return $new;
    }

    public function trace(){
        return $this->filename.':'.$this->getNumLine();
    }

    /**
     * @param array|null $extra
     * @return bool
     * @throws Exception
     */
    public function convertFile($extra=null){
        $this->_restart();
        if(fseek($this->origin, 0)!=0) throw new Exception("No se puede leer el origen");
        if(!is_string($ln=$this->nextLine())) throw new Exception("Inicio inesperado");
        if(!preg_match(self::REGEX_BEGIN_DOC, $ln, $m)) throw new Exception("Inicio inesperado");
        $this->_add('$info["extra"]='.$this->_export($extra).';');
        $this->_add('$info["attrs"]='.$this->_export($this->attrsToArray($m[2]??'')).';');
        unset($m);
        $this->_add('$call=function('.Control::class.' '.self::VAR_CONTROL.', ?'.Scope::class.' $D=null){');
        $this->lvl[]=self::TYPE_MAIN;
        $this->_add(self::VAR_SCOPE.'=new '.Scope::class.'($D??new '.Scope::class.'());');
        $this->_add(self::VAR_CONTROL.'->start();');
        while(is_string($ln=$this->nextLine())){
            $this->runLine($ln);
        }
        if(count($this->lvl)!=0){
            $last=array_pop($this->lvl);
            throw new Exception("Final inesperado: '{$last}'");
        }
        $this->_add('return $call;');
        $this->success=true;
        return true;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool{
        return $this->success;
    }

    /**
     * @return int
     */
    public function getNumLine(): int{
        return $this->num_line;
    }

    /**
     * @param string $filename
     * @return false|int
     * @throws Exception
     */
    public function saveToFile(string $filename){
        if(!$this->success) return false;
        $info=$this->infoCode();
        if(fseek($this->result, 0)==-1) return false;
        if(!($dest=fopen($filename, 'w+'))) return false;
        fwrite($dest, "<?php\n");
        fwrite($dest, $info);
        $copy=stream_copy_to_stream($this->result, $dest);
        fclose($dest);
        return $copy;
    }

    /**
     * @param $dest
     * @return false|int
     * @throws Exception
     */
    public function saveToResource($dest){
        if(!$this->success) return false;
        $info=$this->infoCode();
        if(fseek($this->result, 0)==-1) return false;
        if(!is_resource($dest)) return false;
        fwrite($dest, "<?php\n");
        fwrite($dest, $info);
        $copy=stream_copy_to_stream($this->result, $dest);
        return $copy;
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function saveToOutput(){
        if(!$this->success) return false;
        $info=$this->infoCode();
        if(fseek($this->result, 0)==-1) return false;
        echo "<?php\n";
        echo $info;
        $copy=fpassthru($this->result);
        return $copy;
    }

    public function usesProp(){
        return array_keys($this->useProps);
    }

    public function usesFn(){
        return array_keys($this->useFn);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function infoCode(){
        $str='$info=[]'.";\n";
        $str.='$info["use_prop"]='.$this->_export($this->usesProp()).";\n";
        $str.='$info["use_fn"]='.$this->_export($this->usesFn()).";\n";
        return $str;
    }

    /**
     * @return false|string
     */
    private function nextLine(){
        $ln=fgets($this->origin);
        ++$this->num_line;
        return $ln;
    }

    /**
     * @param $var
     * @param bool $enclose
     * @return array|string|string[]|null
     * @throws Exception
     */
    private function _export($var, bool $enclose=false){
        if(is_array($var) || is_object($var)){
            $res=[];
            foreach($var AS $k=>$v){
                $res[]=(is_string($k)?$this->_export($k).'=>':'').$this->_export($v);
            }
            $res=(is_object($var)?'(object)':'').'['.implode(', ', $res).']';
        }
        elseif(is_string($var)){
            $res=preg_replace_callback('/[\r\t\f\n]+/', function($m){
                return '\' . "'.str_replace(["\r","\t","\f","\n"], ['\r','\t','\f','\n'], $m[0]).'" . \'';
            }, var_export($var, true));
            $res=preg_replace("/([^\\\\])(?:\.\s*\'\'|\'\'\s*\.)/", '$1', $res);
            if($enclose) $res='('.$res.')';
        }
        elseif(is_null($var)){
            $res='null';
        }
        elseif(is_bool($var)){
            $res=$var?'true':'false';
        }
        elseif(is_int($var) || is_float($var)){
            $res=strval($var);
        }
        else{
            throw new Exception('Var export error');
        }
        return $res;
    }

    /**
     * @param string $name
     * @param bool $enclose
     * @return array|string|string[]|null
     * @throws Exception
     */
    private function _as_scalar(string $name, bool $enclose=false){
        if(is_numeric($name) || in_array($name,self::CONSTANTES)) return strval($name);
        if($name!=='' && in_array($name[0], ['"', "'"])){
            if(!preg_match("/^(".self::PREG_STRING.")$/", $name)) return null;
            /**
             * Genera caracteres que coinciden con las experesiones regulares:
             * \xhh: Valor hexadecimal (hh)
             * \r: El carácter de retorno de carro
             * \t: El carácter de tabulador
             * \n: El carácter de nueva línea (avance de línea)
             * .: Cualquier otro caracter
             */
            $r=preg_replace_callback('/\\\(x[0-9a-fA-F]{1,2}|.|$)/', function($m)use(&$name){
                $m=$m[1];
                if($m==='') throw new Exception("Final inesperado del string: '{$name}'");
                elseif($m==='r') return "\r";
                elseif($m==='t') return "\t";
                elseif($m==='n') return "\n";
                elseif(strlen($m)>1 && $m[0]==='x') return hex2bin(str_pad(substr($m, 1), 2, '0', STR_PAD_LEFT));
                return $m;
            }, substr($name, 1, -1));
            if(!is_string($r)) return null;
            return $this->_export($r, $enclose);
        }
        return null;
    }

    /**
     * @param $name
     * @return string
     * @throws Exception
     */
    private function _localName($name){
        if(!preg_match(self::NAME_SIMPLE, $name)) throw new Exception("Nombre inválido: '{$name}'");
        return self::VAR_SCOPE.'->'.$name;
    }

    /**
     * @param $name
     * @return array|string|string[]
     * @throws Exception
     */
    private function _getVar($name){
        $scalar=$this->_as_scalar($name, true);
        if($scalar!==null) return $scalar;
        if(preg_match(self::NAME_SIMPLE, $name, $m)){
            return self::VAR_SCOPE.'->'.$m[1];
        }
        elseif(preg_match(self::REGEX_SCOPE_ALL, $name, $m)){
            if($m[1]==self::SCOPE_ROOT) return self::VAR_SCOPE.'->R()->'.$m[2];
            elseif($m[1]==self::SCOPE_PARENT) return self::VAR_SCOPE.'->P()->'.$m[2];
            elseif($m[1]==self::SCOPE_AUTO) return self::VAR_SCOPE.'->_'.$m[2];
        }
        elseif(preg_match(self::NAME_PROP, $name, $m)){
            $this->useProps[$m[1]]=null;
            return self::VAR_CONTROL.'->'.$m[1];
        }
        throw new Exception("Nombre variable inválida: '{$name}'");
    }

    /**
     * @param $name
     * @return string
     * @throws Exception
     */
    private function _setVar($name){
        if(preg_match(self::NAME_SIMPLE, $name, $m)){
            return self::VAR_SCOPE.'->'.$m[1];
        }
        elseif(preg_match(self::REGEX_SCOPE_ALL, $name, $m)){
            if($m[1]==self::SCOPE_ROOT) return self::VAR_SCOPE.'->R()->'.$m[2];
            elseif($m[1]==self::SCOPE_PARENT) return self::VAR_SCOPE.'->P()->'.$m[2];
            elseif($m[1]==self::SCOPE_AUTO) return self::VAR_SCOPE.'->_'.$m[2];
        }
        elseif(preg_match(self::NAME_PROP, $name)){
            throw new Exception("Propiedad no modificable: '{$name}'");
        }
        elseif(!is_null($this->_as_scalar($name))){
            throw new Exception("Valor no modificable: '{$name}'");
        }
        throw new Exception("Valor inválido: '{$name}'");
    }

    /**
     * @param string $code
     * @param $count
     * @param $extra
     * @param $toArray
     * @return string
     * @throws Exception
     */
    private function _group(string $code, &$count, &$extra=null, $toArray=false){
        $extra=null;
        $begin=$code[0];
        if($begin=='['){
            $end=']';
        }
        elseif($begin=='('){
            $end=')';
        }
        else{
            throw new Exception("Grupo inválido: '$code'");
        }
        $args=[];
        $extra=substr($code, 1);
        while(is_string($extra) && strlen($extra)>0){
            if($extra[0]===$end) break;
            $add=$this->_code($extra, $x);
            $extra=self::nulltrim($x);
            if($extra===null){
                throw new Exception("Grupo incompleto: '$code'");
            }
            elseif($begin==='[' && $extra[0]===':'){
                $extra=trim(substr($extra, 1));
                $add.='=>'.$this->_code($extra, $x);
                $extra=$x;
            }
            if($extra[0]===','){
                $extra=trim(substr($extra, 1));
                $args[]=$add;
            }
            else{
                if($extra[0]!==$end) throw new Exception("Código inesperado: '$extra'");
                $args[]=$add;
                break;
            }
        }
        if(($extra[0]??null)!==$end) throw new Exception("Grupo incompleto: '$code'");
        $count=count($args);
        $extra=self::nulltrim(substr($extra, 1));
        if($toArray) [$begin,$end]=['[',']'];
        return $begin.implode(', ', $args).$end;
    }

    /**
     * @param $code
     * @param $extra
     * @return string|null
     * @throws Exception
     */
    private function _compCode($code, &$extra=null, &$parenthesis=false, &$op=null){
        $code=trim($code);
        $extra=null;
        $parenthesis=false;
        $op=null;
        if($code==='') throw new Exception("Código inválido: '$code'");
        if($code[0]==='['){
            $op=$code[0];
            $r=$this->_group($code, $count, $extra);
            if($count==0){
                throw new Exception("Grupo sin contenido: '$code'");
            }
            elseif($count>1){
                throw new Exception("Grupo solo admite un valor: '$code'");
            }
            if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par2, $op2))){
                if(!in_array($op2, ['[', '??',])){
                    $r.='??null)';
                    $parenthesis=true;
                }
                else{
                    $parenthesis=$par2;
                }
                $r.=$comp;
                $extra=$x;
            }
            else{
                $r.='??null)';
                $parenthesis=true;
            }
            return $r;
        }
        elseif(preg_match('/^(===|!==)(.*)$/', $code, $m) || preg_match('/^(>=|<=|==|!=|&&|\|\||\?\?)(.*)$/', $code, $m) || preg_match('/^(>|<|\?)(.*)$/', $code, $m)){
            $rest=$m[2];
            $op=$m[1];
            unset($m);
            if($op=='?'){
                if($rest[0]===':'){
                    $then='';
                    $extra=substr($rest, 1);
                }
                else{
                    $then=$this->_code($rest, $extra);
                    if($extra[0]!=':') throw new Exception("Código inválido: ".$rest);
                    $extra=substr($extra, 1);
                }
                $else=$this->_code($extra, $x);
                $extra=$x;
                $r=$op.$then.':'.$else;
                return $r;
            }
            else{
                $r=$op.$this->_code($rest, $extra);
                return $r;
            }
        }
        return null;
    }

    /**
     * @param $code
     * @param $extra
     * @return array|string|string[]
     * @throws Exception
     */
    private function _code($code, &$extra=null){
        $code=trim($code);
        $extra=null;
        if($code==='') throw new Exception("Código vacío");
        elseif($code[0]==='!'){
            $r='!'.$this->_code(substr($code, 1), $extra);
            if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                $r.=$comp;
                if($par) $r='('.$r;
                $extra=$x;
            }
            return $r;
        }
        elseif($code[0]==='('){
            $r=$this->_group($code, $count, $extra);
            if($count==0){
                throw new Exception("Grupo sin contenido: ".$code);
            }
            elseif($count>1){
                throw new Exception("Grupo solo admite un valor: ".$code);
            }
            if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                $r.=$comp;
                if($par) $r='('.$r;
                $extra=$x;
            }
            return $r;
        }
        elseif($code[0]==='['){
            $r=$this->_group($code, $count, $extra);
            if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                $r.=$comp;
                if($par) $r='('.$r;
                $extra=$x;
            }
            return $r;
        }
        elseif(preg_match(self::INIT_PROP, $code, $m)){
            $extra=self::nulltrim($m[2]);
            $name=trim($m[1]);
            if(($extra[0]??null)!=='('){
                $r=$this->_getVar($name);
                if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                    $r.=$comp;
                    if($par) $r='('.$r;
                    $extra=$x;
                }
                return $r;
            }
            $fnName=Control::fnSpecial_name($name);
            if(!$fnName) throw new Exception("Función no encontrada: ".$name);
            $this->useFn[$name]=null;
            $r=self::VAR_CONTROL.'->'.$fnName;
            $params=$this->_group($extra, $count, $x);
            $r.=$params;
            $extra=$x;
            if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                $r.=$comp;
                if($par) $r='('.$r;
                $extra=$x;
            }
            return $r;
        }
        elseif(preg_match(self::REGEX_CODE, $code, $m) && ($m[1]??'')!==''){
            $r=$this->_setVar($m[1]).' = ';
            $r.=$this->_code($m[2], $extra);
            if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                $r.=$comp;
                if($par) $r='('.$r;
                $extra=$x;
            }
            return $r;
        }
        elseif(preg_match(self::INIT_SCOPE, $code, $m)){
            $extra=self::nulltrim($m[2]);
            $name=trim($m[1]);
            if(($extra[0]??null)!=='('){
                $r=$this->_getVar($name);
                if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                    $r.=$comp;
                    if($par) $r='('.$r;
                    $extra=$x;
                }
                return $r;
            }
            if(preg_match(self::REGEX_SCOPE_ALL, $name, $m)){
                $fnName=Scope::fnSpecial_name($name);
                if(!$fnName) throw new Exception("Función no encontrada: ".$name);
                $this->useFn[$name]=null;
                $r=self::VAR_SCOPE.'->'.$fnName;
                $params=$this->_group($extra, $count, $x);
                $r.=$params;
                $extra=$x;
                if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                    $r.=$comp;
                    if($par) $r='('.$r;
                    $extra=$x;
                }
                return $r;
            }
            $this->useFn[$name]=null;
            if(!preg_match(self::NAME_SIMPLE, $name)) throw new Exception('Función inválida: '.$m[1]);
            $r=self::VAR_CONTROL.'->fn('.$this->_export($name);
            $params=$this->_group($extra, $count, $x, true);
            if($count>0) $r.=', '.$params;
            $r.=')';
            $extra=$x;
            if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                $r.=$comp;
                if($par) $r='('.$r;
                $extra=$x;
            }
            return $r;
        }
        elseif(preg_match(self::INIT_NUMERIC, $code, $m)){
            $extra=self::nulltrim($m[2]);
            $r=$this->_as_scalar($m[1]);
            if($r===null) throw new Exception('String inválido: '.$code);
            if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                $r.=$comp;
                if($par) $r='('.$r;
                $extra=$x;
            }
            return $r;
        }
        elseif(preg_match(self::INIT_STRING, $code, $m)){
            $extra=self::nulltrim($m[2]);
            $r=$this->_as_scalar($m[1], true);
            if($r===null) throw new Exception('String inválido: '.$code);
            if($extra!==null && !is_null($comp=$this->_compCode($extra, $x, $par))){
                $r.=$comp;
                if($par) $r='('.$r;
                $extra=$x;
            }
            return $r;
        }
        throw new Exception("Código inválido: '$code'");
    }

    /**
     * @param string $line
     * @return string|null
     * @throws Exception
     */
    private function _action(string $line){
        $line=trim($line);
        if(!preg_match(self::REGEX_ACTION, $line, $m)) return null;
        $m[1]=strtoupper($m[1]);
        $m[2]=trim($m[2]);
        if($m[1]==='@UNSET'){
            if(!preg_match('/^\((.*)\)$/', $m[2])) throw new Exception('Parametros inválidos: '.$m[2]);
            $params=array_map([$this, '_setVar'], array_map('trim', explode(',', substr($m[2], 1, -1))));
            $r='unset('.implode(', ', $params).')';
        }
        elseif($m[1]==='@STOP'){
            $r='throw new '.Stop::class;
            if($m[2]==='' || $m[2]==='()'){
                $r.='()';
            }
            else{
                if(!preg_match('/^\((.*)\)$/', $m[2])) throw new Exception('Parametros inválidos: '.$m[2]);
                $r.=$this->_code($m[2]);
            }
        }
        elseif($m[1]==='@LEAVE'){
            if($m[2]!=='') throw new Exception('Código inesperado: '.$m[2]);
            if(!count(array_intersect([
                self::TYPE_IF,
                self::TYPE_ELSEIF,
                self::TYPE_FOR,
                self::TYPE_ELSEFOR,
                self::TYPE_FOREACH,
                self::TYPE_ELSEFOREACH,
                self::TYPE_BUFFER,
            ], $this->lvl))){
                throw new Exception('@leave fuera del scope');
            }
            $r='return';
        }
        elseif($m[1]==='@CONTINUE'){
            if($m[2]!=='') throw new Exception('Código inesperado: '.$m[2]);
            if(!count(array_intersect([
                self::TYPE_FOR,
                self::TYPE_ELSEFOR,
                self::TYPE_FOREACH,
                self::TYPE_ELSEFOREACH,
            ], $this->lvl))){
                throw new Exception('@continue fuera del iterador');
            }
            $r='throw new '.LoopContinue::class.'()';
        }
        elseif($m[1]==='@BREAK'){
            if($m[2]!=='') throw new Exception('Código inesperado: '.$m[2]);
            if(!count(array_intersect([
                self::TYPE_FOR,
                self::TYPE_ELSEFOR,
                self::TYPE_FOREACH,
                self::TYPE_ELSEFOREACH,
            ], $this->lvl))){
                throw new Exception('@break fuera del iterador');
            }
            $r='throw new '.LoopBreak::class.'()';
        }
        elseif($m[1]==='@IF'){
            if(!preg_match('/^\((.*)\)\:(.*)$/', $m[2], $params)) throw new Exception('@if inválido: '.$m[2]);
            $params[1]=trim($params[1]);
            $params[2]=trim($params[2]);
            $params[1]=$this->convertCode($params[1]);
            $params[2]=($this->_action($params[2])??$this->convertCode($params[2]));
            $r='if('.$params[1].') '.$params[2];
        }
        else{
            throw new Exception('Código inválido: '.$line);
        }
        try{
            if(!eval('return function(){'.$r.';};')) throw new Exception('Código inválido: '.$line);
        }
        catch(\ParseError $err){
            throw new Exception('Código inválido: '.$line, 0, $err);
        }
        return $r;
    }

    /**
     * @param string $code
     * @return array|string|string[]
     * @throws Exception
     */
    protected function convertCode(string $code){
        $r=$this->_code($code, $extra);
        if($extra!==null) throw new Exception("Código inválido: ".$extra);
        try{
            if(!eval('return function(){'.$r.';};')) throw new Exception('Código inválido: '.$code);
        }
        catch(\ParseError $err){
            throw new Exception('Código inválido: '.$code, 0, $err);
        }
        return $r;
    }

    /**
     * @param string $line
     * @return array|string|string[]|null
     * @throws Exception
     */
    private function convertLine(string $line){
        if(preg_match(self::REGEX_PRINT, $line, $m)){
            if(($m[1]??'')!=='') $r[]=$this->convertLine($m[1]);
            $r[]='('.$this->convertCode($m[2]).')';
            if(($m[3]??'')!=='') $r[]=$this->convertLine($m[3]);
            return implode('.', $r);
        }
        else{
            return $this->_export($line);
        }
    }

    private function _print(string $line){
        $this->_add(self::VAR_CONTROL.'->out('.$line.');');
    }

    private function _add(string $line, int $lvlAdd=0){
        fwrite($this->result, str_repeat('    ', $lvlAdd+count($this->lvl)).$line."\n");
    }

    /**
     * @param string $line
     * @return void
     * @throws Exception
     */
    private function runLine(string $line){
        $else=$this->nextElse;
        $this->nextElse=false;
        if($this->mode==='print'){
            if(preg_match(self::REGEX_END_PRINT, $line)){
                $this->mode='';
            }
            else{
                $this->_print($this->_export($line));
            }
            return;
        }
        if($this->mode==='do'){
            $line=trim($line);
            if($line===''){
                $this->_add($line);
            }
            elseif(preg_match(self::REGEX_END_DO, $line)){
                $this->mode='';
            }
            elseif(preg_match(self::REGEX_COMMENT, $line)){
                $this->_add($line);
            }
            else{
                $this->_add(($this->_action($line)??$this->convertCode($line)).';');
            }
            return;
        }
        elseif(trim($line)=='</'.self::TYPE_MAIN.'>'){
            $this->_closeControl(self::TYPE_MAIN);
            return;
        }
        elseif(preg_match(self::REGEX_END_PROC, $line, $m)){
            $m[1]=self::nulltrim($m[1]);
            if($m[1]!==null){
                $this->runLine($m[1]);
            }
            $type=$m[2];
            $this->_closeControl($type);
            return;
        }
        elseif(preg_match(self::REGEX_BEGIN_PROC, $line, $m)){
            $type=$m[1];
            $this->_openControl($type, $m[2], $m[3], $else);
            return;
        }
        $this->_print($this->convertLine($line));
    }

    /**
     * @param $attrs
     * @return array
     * @throws Exception
     */
    private function attrsToArray($attrs){
        $params=@simplexml_load_string('<a '.$attrs.'></a>');
        if($params===false) throw new Exception("Atributos corruptos: '$attrs'");
        $params=array_map('strval', iterator_to_array($params->attributes()));
        return $params;
    }

    /**
     * @param string $type
     * @param string|null $attrs
     * @param string $eval
     * @param bool $else
     * @return void
     * @throws Exception
     */
    private function _openControl(string $type, ?string $attrs, string $eval, bool $else){
        if(!in_array($type, self::INDEX_TYPES, true)) throw new Exception("Control inesperado: '$type'");
        if(!$else && in_array($type, [
            self::TYPE_ELSE,
            self::TYPE_ELSEIF,
            self::TYPE_ELSEFOR,
            self::TYPE_ELSEFOREACH,
        ])){
            throw new Exception('Else no permitido');
        }
        $params=$this->attrsToArray($attrs);
        $eval=trim($eval);
        if($type===self::TYPE_DO){
            $this->mode='do';
            if($eval!=='') $this->runLine($eval);
            return;
        }
        elseif($type===self::TYPE_PRINT){
            $this->mode='print';
            if($eval!=='') $this->runLine($eval);
            return;
        }
        elseif($type===self::TYPE_BUFFER){
            $this->_add($this->_setVar($params['set']).'='.self::VAR_CONTROL.'->buffer('.self::VAR_SCOPE.', function('.Scope::class.' &'.self::VAR_SCOPE.')use(&'.self::VAR_CONTROL.'){');
            $this->lvl[]=$type;
            if($eval!=='') $this->runLine($eval);
        }
        elseif($type===self::TYPE_ELSE){
            $this->_add('else{');
            $this->lvl[]=$type;
            if($eval!=='') $this->runLine($eval);
        }
        elseif($type===self::TYPE_IF || $type===self::TYPE_ELSEIF){
            if(substr($eval, 0, 2)!==self::PROC_VALUE) throw new Exception('Falta la condición');
            $val=$this->convertCode(substr($eval, 2));
            $this->_add(($type===self::TYPE_ELSEIF?'else':'').'if('.$val.'){');
            $this->_add(self::VAR_CONTROL.'->run('.self::VAR_SCOPE.', function('.Scope::class.' &'.self::VAR_SCOPE.')use(&'.self::VAR_CONTROL.'){');
            $this->lvl[]=$type;
        }
        elseif($type===self::TYPE_FOR || $type===self::TYPE_ELSEFOR){
            if(substr($eval, 0, 2)!==self::PROC_VALUE) throw new Exception('Falta el valor de iteraciones');
            $val=$this->convertCode(substr($eval, 2));
            $args=[];
            if(isset($params['position']) && $this->_localName($params['position'])) $args['position']=$params['position'];
            $this->_add(($type===self::TYPE_ELSEFOR?'else':'').'if('.self::VAR_CONTROL.'->for('.self::VAR_SCOPE.', '.$val.', '.$this->_export($args).', function('.Scope::class.' &'.self::VAR_SCOPE.')use(&'.self::VAR_CONTROL.'){');
            $this->lvl[]=$type;
        }
        elseif($type===self::TYPE_FOREACH || $type===self::TYPE_ELSEFOREACH){
            if(substr($eval, 0, 2)!==self::PROC_VALUE) throw new Exception('Falta el valor iterable');
            $val=$this->convertCode(substr($eval, 2));
            $args=[];
            if(isset($params['position']) && $this->_localName($params['position'])) $args['position']=$params['position'];
            if(isset($params['key']) && $this->_localName($params['key'])) $args['key']=$params['key'];
            if(isset($params['value']) && $this->_localName($params['value'])) $args['value']=$params['value'];
            if(isset($params['offset'])){
                $args['offset']=$this->_getVar($params['offset']);
            }
            if(isset($params['limit'])){
                $args['limit']=$this->_getVar($params['limit']);
            }
            $this->_add(($type===self::TYPE_ELSEFOREACH?'else':'').'if('.self::VAR_CONTROL.'->foreach('.self::VAR_SCOPE.', '.$val.', '.$this->_export($args).', function('.Scope::class.' &'.self::VAR_SCOPE.')use(&'.self::VAR_CONTROL.'){');
            $this->lvl[]=$type;
        }
        else{
            throw new Exception("Control inesperado '{$type}'");
        }
    }

    /**
     * @param $type
     * @return void
     * @throws Exception
     */
    private function _closeControl($type){
        if(!in_array($type, self::INDEX_TYPES, true)) throw new Exception("Cierre inesperado: '$type'");
        if($type===self::TYPE_DO){
            if($this->mode!=='do')
                throw new Exception("Cierre inesperado '{$type}'");
            $this->mode='';
            return;
        }
        elseif($type===self::TYPE_PRINT){
            if($this->mode!=='print') throw new Exception("Cierre inesperado '{$type}'");
            $this->mode='';
            return;
        }
        $last=array_pop($this->lvl);
        if($last!==$type) throw new Exception("Cierre inesperado '{$type}'");
        if($type===self::TYPE_MAIN){
            if(count($this->lvl)>0) throw new Exception("Final inesperado '{$type}'");
            $this->_add('return '.self::VAR_SCOPE.";", 1);
            $this->_add('};');
            return;
        }
        elseif($type===self::TYPE_ELSE){
            $this->_add('}');
        }
        elseif($type===self::TYPE_IF || $type===self::TYPE_ELSEIF){
            $this->nextElse=true;
            $this->_add('});');
            $this->_add('}');
        }
        elseif($type===self::TYPE_FOR || $type===self::TYPE_ELSEFOR){
            $this->nextElse=true;
            $this->_add('})){}');
        }
        elseif($type===self::TYPE_FOREACH || $type===self::TYPE_ELSEFOREACH){
            $this->nextElse=true;
            $this->_add('})){}');
        }
        elseif($type===self::TYPE_BUFFER){
            $this->_add('});');
        }
        else{
            throw new Exception("Cierre inesperado '{$type}'");
        }
    }

    public static function nulltrim($s){
        if($s===null) return $s;
        if(($s=trim($s))==='') $s=null;
        return $s;
    }
}
