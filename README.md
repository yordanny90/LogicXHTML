[repo]:  https://github.com/yordanny90/LogicXHTML
[iconGit]: http://www.google.com/s2/favicons?domain=www.github.com
[control]: src/LogicXHTML/Control.php
[scope]: src/LogicXHTML/Scope.php

# LogicXHTML

Es una librería para la generación de HTML de terceros de forma segura

Permite a una programador externo al servicio de PHP, crear una serie de instrucciones lógicas para ser ejecutadas por PHP con el fin de generar un HTML como resultado. 
Para lo que solo se le permitirá acceder a una lista limitada de instrucciones predefinidas en la aplicación de PHP

[Ir a ![GitHub CI][iconGit]][repo]

# Requisitos mínimos

PHP 7.1+, PHP 8.0+

Función `eval` habilitada (No ejecuta código, solo utilizado como verificador de código PHP)

# Formato del código

## LogicXHTML

Etiqueta principal que de la estructura XHTML

Cualquier línea dentro de esta etiqueta sera agregada al resultado o interpretada, según según sea el caso

Todo el código debe estár dentro de estas etiquetas

```xhtml
<LogicXHTML>
    <h1>Este título</h1>
    <p>Este párrafo es parte el resultado</p>
</LogicXHTML>
```

Mediante el [Converter](src/LogicXHTML/Converter.php) podrá convertir el código XHTML en una función PHP que recibirá un [Control][control] para generar una salida

# Funciones y propiedades

Las funciones se definen como parte del [Control][control] para ser llamadas desde el LogicXHTML

Las propiedades se definen como parte del [Control][control] mediante un [Scope][scope] para ser leídas desde el LogicXHTML

Tanto las funciones como las propiedades son de solo lectura dentro del LogicXHTML

```php
$control=new \LogicXHTML\Control();
$control->set_fn('saludo', function($nombre){
    return 'Hola '.$nombre;
});
$control->fn_bind([
    'myFn1'=>function(){},
    'myFn2'=>function(){},
]);
// Agrega funciones útiles como operaciones matemáticas y manipulacion de string
$control->fn_bind(\LogicXHTML\Control::fn_plus());
// Se definen las propiedades mediante un Scope
$props=new \LogicXHTML\Scope();
$props->prop_1='Valor 1';
$props->prop_2='Valor 2';
$control->setProps($props);
$converter=\LogicXHTML\Converter::loadFile('archivo.xhtml'));
if($converter->convert()){
    $fn=$converter->saveToFunction();
    $fn($control);
    $control->saveToOutput();
}
```

En el `archivo.xhtml` se llaman las funciones con sus respectivos parametros, y la lectura de propiedades se hace por medio del simbolo `$`
```xhtml
<LogicXHTML>
    <!--Esto imprime: Hola mundo-->
    :{saludo("mundo")}:
    <!--Esto imprime: Valor 1-->
    :{$.prop_1}:
</LogicXHTML>
```

# Llaves de reemplazo

Las llaves de reemplazo permiten ejecutar código para incluirlo al resultado

```html
<div>:{"Este texto se interpreta"}:</div>
```

# Variables

Para el manejo de variables, primero debemos cononer los [Scope](Scope)
- <b>R</b>: Accede a la raíz, que es el scope inicial o principal
- <b>L</b>: Local. Accede solo al scope más inmediato, no accede a sus padres
- <b>P</b>: Parent. Accede solo al scope padre, no accede a otros superiores
- <b>U</b>: Superior. Accede a todos los scopes superiores, excepto al local
- <b>_</b>: Auto. Accede a todos los scopes, desde el local hasta la raíz

Para leer o escribir una variable, podemos indicar su nombre en los controles que lo permitan
```html
<:DO>
    R.var='Variable en la raíz'
    P.var='Variable en el padre'
    L.var='Variable en el local'
</:DO>
<!--Esto imprime: Variable en el local--> 
:{var}:
<!--Esto imprime: Variable en el local-->
:{_.var}:
<!--Esto imprime: Variable en el local-->
:{L.var}:
<!--Esto imprime: Variable en el padre-->
:{U.var}:
<!--Esto imprime: Variable en el padre-->
:{P.var}:
<!--Esto imprime: Variable en la raíz-->
:{R.var}:
```

# Estructuras lógicas

Estas estructuras lógicas esperan el resultado del código que les sucede junto con los caracteres `:=`, excepto `<:ELSE>` que depende del resultado de la estructura anterior

El comportamiento de la estructura depende del resultado

## <:IF>

Ejecuta el código en su interior solo si la condición del resultado es equivalente a `TRUE`

Crea su propio [Scope][scope] local para el uso de variables

```html
<:DO>a=0</:DO>
<:IF>:= a==0
    Este texto se agrega al resultado
</:IF>
<:IF>:= a==2
    Este texto no se agrega al resultado
</:IF>
```

## <:ELSEIF>

Mismo comporamiento que el `<:IF>` pero la condición anterior no se debe cumplir
```html
<:DO>a=1</:DO>
<:IF>:= a==0
    Este texto no se agrega al resultado
</:IF>
<:ELSEIF>:= a==1
    Este texto si se agrega al resultado
</:ELSEIF>
<:ELSEIF>:= a==2
    Este texto no se agrega al resultado
</:ELSEIF>
```

## <:FOR>

Crea su propio [Scope][scope] local para el uso de variables

## <:ELSEFOR>

Mismo comporamiento que el `<:FOR>` pero la condición anterior no se debe cumplir

## <:FOREACH>

Crea su propio [Scope][scope] local para el uso de variables

## <:ELSEFOREACH>

Mismo comporamiento que el `<:FOREACH>` pero la condición anterior no se debe cumplir

## <:ELSE>

La condición anterior no se debe cumplir. No tiene su propio Scope

# Otras estructuras de control

## <:BUFFER>

No tiene un comportamiento especial, pero todo lo que se agregue a al resultado dentor de esta etiqueta, se asignará a la variable indicada en su atributo `set`

Crea su propio [Scope][scope] local para el uso de variables

```html
<:BUFFER set="temp">
    <p>Parrafo guardado en la variable temp</p>
</:BUFFER>
<!--Imprime el contenido generado en el buffer-->
:{temp}:
```

## <:PRINT>

Todo el contenido dentro de esta etiqueta se imprime en la salida, ninguna linea es interpretada

## <:DO>

Todo el contenido dentro de esta etiqueta es interpretado, pero no se incluye en el resultado

Cada línea debe ser código ejecutable, o bien, un comentario (Los primero caracteres de la linea deben ser //)

Acciones especiales: Solo se pueden ejecutar desde una etiqueta `<:DO>`

### @IF
### @UNSET
### @STOP
### @CONTINUE
### @BREAK
### @LEAVE

