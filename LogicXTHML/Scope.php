<?php

namespace LogicXHTML;

use Traversable;

class Scope implements \Countable, \IteratorAggregate{
    /**
     * @see Scope::get()
     * @see Scope::keys()
     * @see Scope::count()
     * @see Scope::P_get()
     * @see Scope::P_keys()
     * @see Scope::P_count()
     * @see Scope::R_get()
     * @see Scope::R_keys()
     * @see Scope::R_count()
     */
    const FN_SPECIAL=[
        '_.get'=>'get',
        '_.keys'=>'keys',
        '_.count'=>'count',
        'P.get'=>'P_get',
        'P.keys'=>'P_keys',
        'P.count'=>'P_count',
        'R.get'=>'R_get',
        'R.keys'=>'R_keys',
        'R.count'=>'R_count',
    ];
    const MAX_LEVEL=255;
    /**
     * @var Scope
     */
    private $_P;
    private $_D=[];

    public static function fnSpecial_name(string $name){
        return self::FN_SPECIAL[$name]??null;
    }

    /**
     * @param Scope|null $parent
     */
    public function __construct(?Scope $parent=null){
        $this->_P=$parent;
    }

    public function fromArray(array $data){
        foreach($data AS $k=>$v){
            $this->$k=$v;
        }
    }

    public function isolate(int $levels=0){
        $t=clone $this;
        $t->_P=null;
        if($this->_P && $levels>0){
            $t->_P=$this->_P->isolate($levels-1);
        }
        return $t;
    }

    private function _level(int $level): int{
        if($this->_P && $level<self::MAX_LEVEL) return $this->_P->_level($level+1);
        return $level;
    }

    public function level(): int{
        return $this->_level(0);
    }

    private function &_R(int $level){
        if($this->_P && $level<self::MAX_LEVEL) return $this->_P->_R($level+1);
        return $this;
    }
    /**
     * Devuelve el conjunto de datos raíz. Si no tiene, devuelve el mismo objeto
     * @return Scope
     */
    public function &R(){
        return $this->_R(0);
    }

    /**
     * Devuelve el conjunto de datos padre. Si no tiene, devuelve el mismo objeto
     * @return Scope
     */
    public function &P(){
        if($this->_P) return $this->_P;
        return $this;
    }

    private function _toArray(int $level){
        if($this->_P && $level<self::MAX_LEVEL) return array_merge($this->_P->_toArray($level+1), $this->_D);
        else return $this->_D;
    }

    private function _keys(int $level){
        if($this->_P && $level<self::MAX_LEVEL) return array_merge($this->_P->_keys($level+1), array_keys($this->_D));
        else return array_keys($this->_D);
    }

    private function _get(string $name, int $level){
        if(key_exists($name, $this->_D)) return $this->_D[$name];
        return ($this->_P && $level<self::MAX_LEVEL)?$this->_P->_get($name, $level+1):null;
    }

    private function _replace(string $name, $value, int $level): bool{
        if(key_exists($name, $this->_D)){
            $this->_D[$name]=$value;
            return true;
        }
        elseif($this->_P && $level<self::MAX_LEVEL){
            return $this->_P->_replace($name, $value, $level+1);
        }
        return false;
    }

    private function _unsetFind(string $name, int $level){
        if(key_exists($name, $this->_D)){
            unset($this->_D[$name]);
            return true;
        }
        elseif($this->_P && $level<self::MAX_LEVEL){
            return $this->_P->_unsetFind($name, $level+1);
        }
        return false;
    }

    public function toArrayIsolated(){
        return $this->_D;
    }

    public function toArray(){
        return $this->_toArray(0);
    }

    public function keys(){
        return array_unique($this->_keys(0));
    }

    public function count(): int{
        return count($this->keys());
    }

    public function get(string $name){
        return $this->_get($name, 0);
    }

    public function R_keys(){
        return $this->R()->keys();
    }

    public function R_count(): int{
        return $this->R()->count();
    }

    public function R_get(string $name){
        return $this->R()->get($name);
    }

    public function P_keys(){
        return $this->P()->keys();
    }

    public function P_count(): int{
        return $this->P()->count();
    }

    public function P_get(string $name){
        return $this->P()->get($name);
    }

    public function set(string $name, $value){
        $this->_D[$name]=$value;
    }

    public function replace(string $name, $value){
        if(!$this->_replace($name, $value, 0)){
            $this->_D[$name]=$value;
        }
    }

    public function unset(string $name){
        unset($this->_D[$name]);
    }

    public function unsetFind(string $name){
        $this->_unsetFind($name, 0);
    }

    public function __unset(string $name): void{
        if(($name[0]??null)==='_'){
            $this->unsetFind(substr($name, 1));
        }
        else{
            $this->unset($name);
        }
    }

    public function __get(string $name){
        if(($name[0]??null)==='_'){
            return $this->get(substr($name, 1));
        }
        else{
            return $this->get($name);
        }
    }

    public function __set(string $name, $value){
        if(($name[0]??null)==='_'){
            $this->replace(substr($name, 1), $value);
        }
        else{
            $this->set($name, $value);
        }
    }

    public function getIterator(): Traversable{
        return new \ArrayIterator($this->toArray());
    }

}
