<?php

namespace LogicXHTML;

use Traversable;

class Scope implements \Countable, \IteratorAggregate{
    /**
     * @see Scope::get()
     * @see Scope::keys()
     * @see Scope::count()
     * @see Scope::U_get()
     * @see Scope::U_keys()
     * @see Scope::U_count()
     * @see Scope::L_get()
     * @see Scope::L_keys()
     * @see Scope::L_count()
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
        'U.get'=>'U_get',
        'U.keys'=>'U_keys',
        'U.count'=>'U_count',
        'L.get'=>'L_get',
        'L.keys'=>'L_keys',
        'L.count'=>'L_count',
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
    private $P;
    private $D=[];

    public static function fnSpecial_name(string $name){
        return self::FN_SPECIAL[$name]??null;
    }

    /**
     * @param Scope|null $parent
     */
    public function __construct(?Scope $parent=null){
        $this->P=$parent;
    }

    public function fromArray(array $data){
        foreach($data AS $k=>$v){
            $this->$k=$v;
        }
    }

    /**
     * Clona el scope actual, desechando los scope padres que se indiquen
     * @param int $levels
     * @return Scope
     */
    public function &L(int $levels=0){
        $t=new static();
        $t->D=&$this->D;
        if($this->P && $levels>0){
            $t->P=&$this->P->L($levels-1);
        }
        return $t;
    }

    private function _level(int $level): int{
        if($this->P && $level<self::MAX_LEVEL) return $this->P->_level($level+1);
        return $level;
    }

    public function level(): int{
        return $this->_level(0);
    }

    private function &_R(int $level){
        if($this->P && $level<self::MAX_LEVEL) return $this->P->_R($level+1);
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
        if($this->P) return $this->P->L();
        return $this;
    }

    /**
     * Devuelve el conjunto de datos padre. Si no tiene, devuelve un scope vacío
     * @return Scope
     */
    public function &U(){
        if($this->P) return $this->P;
        $n=new static();
        return $n;
    }

    private function _toArray(int $level){
        if($this->P && $level<self::MAX_LEVEL) return array_merge($this->P->_toArray($level+1), $this->D);
        else return $this->D;
    }

    private function _keys(int $level){
        if($this->P && $level<self::MAX_LEVEL) return array_merge($this->P->_keys($level+1), array_keys($this->D));
        else return array_keys($this->D);
    }

    private function _get(string $name, int $level){
        if(key_exists($name, $this->D)) return $this->D[$name];
        return ($this->P && $level<self::MAX_LEVEL)?$this->P->_get($name, $level+1):null;
    }

    private function _replace(string $name, $value, int $level): bool{
        if(key_exists($name, $this->D)){
            $this->D[$name]=$value;
            return true;
        }
        elseif($this->P && $level<self::MAX_LEVEL){
            return $this->P->_replace($name, $value, $level+1);
        }
        return false;
    }

    private function _unsetFind(string $name, int $level){
        if(key_exists($name, $this->D)){
            unset($this->D[$name]);
            return true;
        }
        elseif($this->P && $level<self::MAX_LEVEL){
            return $this->P->_unsetFind($name, $level+1);
        }
        return false;
    }

    public function toArrayIsolated(){
        return $this->D;
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

    public function L_keys(){
        return $this->L()->keys();
    }

    public function L_count(): int{
        return $this->L()->count();
    }

    public function L_get(string $name){
        return $this->L()->get($name);
    }

    public function U_keys(){
        return $this->U()->keys();
    }

    public function U_count(): int{
        return $this->U()->count();
    }

    public function U_get(string $name){
        return $this->U()->get($name);
    }

    public function set(string $name, $value){
        $this->D[$name]=$value;
    }

    public function replace(string $name, $value){
        if(!$this->_replace($name, $value, 0)){
            $this->D[$name]=$value;
        }
    }

    public function unset(string $name){
        unset($this->D[$name]);
    }

    public function unsetFind(string $name){
        $this->_unsetFind($name, 0);
    }

    public function __unset(string $name): void{
        $this->unset($name);
    }

    public function __get(string $name){
        return $this->get($name);
    }

    public function __set(string $name, $value){
        $this->set($name, $value);
    }

    public function getIterator(): Traversable{
        return new \ArrayIterator($this->toArray());
    }

}
