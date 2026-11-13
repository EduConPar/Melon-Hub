# Alfa 0.1.1

## Nuevas funciones y cambios

- Añadida la función de álbumes, ahora al darle click al título de una canción en el reproductor se mostrará el álbum al que pertenece. También se mostrará el álbum en la ventana de playlists y será clickable. Desde la página del álbum puedes añadirlo a una playlist y reseñarlo. (Se habilitará cuando se nos vaya el baneo de la API de Spotify)
- Añadida la función de importar pinceles a la app de dibujo.
- Subido el cupo para que se considere Melon Must 4.6 o más de nota media y 2 reseñas.
- Añadida función de escalado de texto en la app de temas.
- Añadida una función para evitar que se sigan mandando request a la API de Spotify durante el baneo y por consecuencia se extienda.
- Añadido guardado automático de temas.

## Bugs y mejoras

- Solucionado el bug que hacía que al hacer una actualización se borraran los datos de los perfiles de los usuarios.
- Mejorado el sistema de búsqueda de canciones importadas desde Spotify y otros sitios que no fueran YouTube.
- Solucionado el bug que hacía que las notificaciones se salieran de su ventana al resizearlas.
- Solucionado el bug que hacía que si el nombre de un usuario era muy largo no saliera el icono del chat en el apartado de Social.
- Mejorado el rendimiento de la app de dibujos en lienzos grandes.
- Solucionado un bug que hacía que al activar o guardar un tema se te pusiera de nuevo el tema default.
- Solucionado un bug que hacía que al tener 2 temas con el mismo nombre en interfaces distintas se sobrepusieran al cambiar de interfaz.
- Mejorado el rendimiento de las tabletas gráficas en la app de dibujo.
- Solucionado el bug que hacía que los perfiles de otros usuarios se pudieran mover demasiado arriba de la pantalla haciendo que no pudieras cerrarlos ni volverlos a mover.
- Solucionado el bug que hacía que las reseñas se salieran de su ventana al resizearlas.
- Solucionado el bug que pedía al usuario añadir un webhook de Discord en lugar de loguearse con su perfil en Galería.

---

# Alfa 0.1.2

## Nuevas funciones y cambios

- Añadida barra de volumen general de la Melon Hub.
- Añadida opción para poder deshabilitar las notificaciones de mensajes, reseñas de gente seguida y likes y comentarios. Se puede hacer desde la ventana de notificaciones.
- Añadido modo no molestar que se activa al deshabilitar las notificaciones de mensajes y se marca al usuario con un color rojo en su botón de conectado.
- Ahora la media reseñada que tengan la etiqueta de "Melon Must X" se muestran por delante del resto de reseñas.

## Bugs y mejoras

- Solucionado un bug que no actualizaba la app de perfil al seguir a alguien.
- Solucionado un bug que hacía que, al ser colaborador de una actividad en la app de perfil, solo se mostrara el host en esa actividad y no a los otros colaboradores invitados.
- Arreglado un bug que hacía que las ventanas de reseña se abrieran horizontalmente ocupando toda la pantalla.

---

# Alfa 0.1.3

## Nuevas funciones y cambios

- Las ventanas de los álbumes ahora son resizeables.
- Cambiada la API de la que se buscan los álbumes de la API de Spotify a la de iTunes y Deezer y dejada la de Spotify como último recurso cuando las 2 primeras fallan. Este cambio es para evitar problemas de baneos ya que la API de Spotify banea con una facilidad absurda en cuanto le haces unas pocas consultas.
- Cambiadas las instrucciones de la pantalla de descarga en móvil para hacerlas más fáciles de interpretar.
- Añadido un botón de eliminar cuenta en la ventana de ajustes de móvil.
- Cambiado el tema de la página de inicio de sesión en el móvil que se muestra al cerrar sesión para que tenga el mismo tema que la página de instalación.
- Añadido un buscador de GIFs que busca GIFs en GIPHY. Es accesible desde la pestaña de emojis.
- Añadida la capacidad de pasar y pinchar en links en el chat.
- Añadido que, al pasar el link de una imagen, salga la imagen de la que se ha pasado el link en el chat.
- Ahora al darle al botón de la campana con la ventana de notificaciones abierta, esta se cierra.

## Bugs y mejoras

- Solucionado un bug que hacía que la ventana de añadir álbum a playlist no tuviera el estilo de la interfaz seleccionada.
- Mejorada la función de búsqueda de álbumes para hacerla más rápida.
- Solucionado un bug que al añadir un álbum desde su página a tu perfil te ponía la foto de la canción que estaba sonando en el reproductor en lugar de la del álbum.
- Optimizado el uso de batería de la pantalla de bloqueo de la versión de móvil.
- Solucionado un bug que hacía que si el usuario se ponía una foto desde la página de descarga de la app de móvil esa foto no se guardaba correctamente.
- Solucionado un error que hacía que al entrar en melonhub.es desde móviles se quedara colgada la app.
- Solucionado un error que hacía que al tener la sesión cerrada y entrar en la app móvil, esta no cargara.
- Arreglado un bug que hacía que la foto de perfil del usuario no apareciera en la app de perfil móvil.
- Solucionado un bug que hacía que al pulsar una notificación de mensaje no te llevara al chat de la persona.
- Arreglado el bug que hacía que al darle repetidamente a publicar un post se publicara varias veces el mismo post.
- Añadido scroll a los posts en móvil.
- Arreglado un bug que hacía que al salir del navegador te devolviera a una página incorrecta y te diera un error diciendo que no existía la página.
- Arreglado el bug que no te dejaba publicar en el canal de arte de Discord desde la Melon Hub.

---

# Alfa 0.1.4

## Nuevas funciones y cambios
- Ahora al importar o añadir una cancion ya no necesitas darle a guardar playlist para que la cancion se reproduzca.
- Al añadir canciones a una playlist la playlist a la que la has añadido se guarda automaticamente
- Añadido una seccion para reportar bugs y añadir sugerencias desde la melon hub. En movil se encuentra en la ventana de ajustes y en pc en el menu del boton de inicio
- Ahora las notificaciones del haro no se van hasta que las pulsas
- Añadido un cooldown al sonido de notificacion del haro para que no spamee el sonido cuando hayan muchas notificaciones juntas
- Añadida una seccion de changelog para que podais leer los cambios desde la melon hub. En movil se encuentra en la ventana de ajustes y en pc en el menu del boton de inicio (Los changelogs se van a seguir posteando en discord igual que siempre)
- Añadido un estado de ausente para cuando el usuario esta mucho tiempo sin usar la pagina
- Ahora puedes ver lo que tus seguidos estan escuchando en el reproductor en el apartado de perfil de la app de social
- Añadidos el nombre de los estados del usuario (En linea/Ausente/No molestar) al chat
- Añadida una funcion de eventos en las que los usuarios pueden crear eventos e invitar a gente, el evento se anunciara tambien en #eventos 

## Bugs y mejoras
- Ahora los archivos .webp, los links de google drive que sean imagenes, y otros tantos se pasan como imagen en el chat
- Arreglado un bug que hacia que, al scrollear hacia arriba en el chat, te tirara hacia abajo de golpe
- Arreglado un bug que hacia que MelonOS 98 se viera en pequeñito en la barra del menu del boton de inicio cuando se tenia puesto la interfaz de MelonOS Overdose
- Solucionado un bug que no dejaba cambiar entre interfaces en la app de movil
- Solucionado un bug que no dejaba importar playlist de youtube
- Solucionado un bug en que la ventana de otros perfiles no se acomodaban a su tamaño
- Solucionado un bug en que la ventana de otros perfiles no se podia resizear en todas sus esquinas

---

# Alfa 0.1.5

## Nuevas funciones y cambios
- Cambiado el sistema de login de una lista con todos los usuarios a dos campos para introducir nombre de usuario y contraseña
- Añadida una funcion para corregir las canciones a las que se les haya asignado un album incorrecto. La informacion se guardara para la posteridad y cuando otra persona añada esta cancion el album sera el corregido
- Añadida opcion para cambiar el minuto de la cancion tocando la barra de progreso en el movil

## Bugs y mejoras
- Mejorada la escucha compartida para evitar cortes en la musica
- Arreglado un bug que hacia que pasado un rato transmitiendo musica en tv, la transmision se colgara y pusiera el tramo de una cancion en bucle hasta reiniciar la app de movil
- Mejorada la transmision a tv para evitar cortes en la musica
- Arreglado un bug en el que, al abrir una reseña desde la seccion de notificaciones de perfil, se te ponia tu reseña en lugar de la de esa persona
- Arreglado un bug que hacia que, al mandar un gif o imagen en un chat y luego mandar un mensaje, el chat no bajara

---

# Alfa 0.1.6

## Nuevas funciones y cambios
- Se ha cambiado la vista de la ventana de playlist para hacerla mas visual y sencilla
- Se ha añadido un motor de busqueda a la ventana de playlist donde ahora puedes buscar canciones, artistas y albumes
- Ahora al darle click al titulo de una cancion dentro de la playlist te lleva a su album
- Ahora al darle click al artista de una cancion tanto en el reproductor como en la playlist te lleva a su pagina de artista donde se encuentra toda su discografia
- Las imagenes de la cancion ahora se extraen de su album en lugar del video de youtube del que extraen el audio
- Se cambio la funcion de "Corregir album" por "Corregir", ahora al darle te permitira editar el titulo, artista, album y link de youtube del que se extrae la musica de una cancion, estos datos se quedaran guardados para cuando otro usuario agregue esa cancion a su playlist
- Se ha añadido un apartado de duracion para las peliculas y capitulos para series y libros en el apartado de reseñas
- Se cambio la funcion de "Editar reseña" por "Editar", ahora al darle te permitira editar la imagen, titulo, reseña y duracion en caso de existir
- Ahora puedes añadir los capitulos de cada una de las series y añadirles una reseña individualmente
- Ahora en las reseñas de los albumes puedes clicar en el titulo del album o en el artista y te abrira una ventana con el contenido del album o el perfil del artista respectivamente
- Se ha añadido que en las ventanas de contenido de album aparezca la nota media si alguna de las canciones del album ha sido reseñada
- Ahora al subir libros se puede adjuntar un link a un pdf. Al darle click en la reseña de melon reviews al titulo del libro, se abrira el pdf que hayan subido los usuarios y podras leer el libro

## Bugs y mejoras
- Arreglado un bug que hacia que al poner una reseña y hacer la ventana mas pequeña, los elementos de la ventana se salieran de esta
- Arreglado un bug en el que el reproductor tenia 2 menus distintos

---

# Alfa 0.1.7

## Nuevas funciones y cambios
- Ahora añadir una imagen a una pelicula, serie, etc es opcional, si no se pone ninguna imagen se pondra una automaticamente teniendo prioridad la que haya puesto otro usuario y, en caso de que no se haya registrado otro usuario, usando el mismo sistema que arregla cuando un link de imagen se cae explicado mas abajo

## Bugs y mejoras
- Arreglado el problema de que cuando se caia un link de una imagen que se habia puesto a una serie, pelicula, etc el item se quedaba sin imagen. Ahora si un link cae busca otra imagen en internet para sustituirla

---

# Alfa 0.2.0

## Aplicaciones de movil y escritorio de la Melon Hub
Presentamos las aplicaciones de movil y escritorio de la melon hub las cuales se pueden descargar desde la propia pagina web, estas aplicaciones vienen con las siguientes funciones:

### Escritorio
- Ahora puedes tener la melon hub en segundo plano sin necesidad de tener la ventana abierta
- Se ha añadido la opción de que, al cerrar la ventana de la melon hub y dejarla en segundo plano, si tienes musica sonando, se abra un pequeño reproductor en el escritorio con el que puedas controlarla. Esta opción se puede desactivar desde el panel que se abre al darle click derecho al icono de segundo plano de la app
- Se ha añadido una integración con la presencia de discord, ahora todos los que esten escuchando musica en la aplicación de la Melon Hub les aparecerá lo que estan escuchando en discord
- Añadido una función para descargar audios de las canciones, puedes haciendolo dandole click derecho a la cancion o a la playlist y seleccionando la opción de descargar
- Desde la presencia de discord otra gente puede unirse a tu sesion y escuchar contigo, la persona solo puede unirse si os seguis mutuamente, si no simplemente se le abrira la melon hub

### Movil
- Ahora al apagar la pantalla o cambiar de aplicación la musica se seguirá reproduciendo
- Se ha eliminado la funcion de bloquear pantalla dentro de la Melon Hub
- Se ha añadido un widget de un minireproductor de musica en el que puedes controlar la reproduccion y cambiar la playlist
- Se han añadido dos widgets de calendario, uno que te muestra los recordatorios proximos y otro que te muestra los dias del mes, el actual y cuales tienen un recordatorio en el
- Ahora las notificaciones tambien aparecen cuando no se esta dentro de la aplicacion
- Se ha cambiado el apartado visual de las notificaciones moviles

## Nuevas funciones y cambios
- Ahora en tu perfil puedes poner tu cumpleaños, cuando dos usuarios sean amigos les saldra sus respectivos cumpleaños como recordatorio anual en el calendario
- Se han bajado los puntos de autismo que recibes por estar en vc a 1 por cada 5 min
- Se ha aumentado el cooldown para recibir puntos de autismo por los mensajes a 10s
- Ahora puedes copiar el link de canciones, albumes, playlist, escuchar juntos, etc. Al pulsar en ellos te abrira primero la aplicación y en su defecto la web
- Se ha aumentado el precio de varios objetos de la tienda
- Se ha hecho un revamp completo al perfil de la Melon Hub, ahora puedes tener distintos expositores, unos vienen de base, pero se pueden mejorar y comprar mas tipos de expositores en la tienda, cada usuario tiene 2 espacios de expositores de default y pueden ser aumentados a 5 comprandolos desde la tienda.
- Ahora puedes conectar tu cuenta de steam a tu perfil, lo que te permitirá acceder a los expositores de logros y logros mas raros añadiendo logros de steam. Ademas, si añades el expositor de juegos favoritos, al poner el raton encima te saldran tus horas a ese juego en steam y la cantidad de logros
- Ahora puedes regalar objetos de la tienda a usuarios que sean tus amigos
- Se ha cambiado el sistema de amigos y social, ahora para agregar a gente tienes que mandarle solicitud de amistad. Ya no podras ver todos los perfiles de la Melon Hub en una lista, tendras que buscarlos por nombre en la seccion de añadir amigos
- Ahora aparece el ultimo tiempo de conexion de tus amigos
- Ahora la seccion de amigos/social se accede desde el perfil del usuario
- Se ha rediseñado el icono de la Melon Hub
- Se ha añadido una aplicación de juegos con 4 juegos nuevos: Wordelon, Buscaminas, Ajedrez y El Solitario. En todos estos juegos se pueden obtener puntos de autismo de una manera u otra.
- Se ha añadido una racha de conexión en el perfil, la cual da puntos de autismo cada dia que es activada, para activarla debes ir a tu perfil y pulsar el icono de fuego cada vez que este gris
- Ahora puedes invitar amigos a la Melon Hub, si estos amigos crean un perfil y activan su racha durante 7 dias seguidos en un lapso de 2 semanas, recibireis 500 puntos cada uno
- Ahora puedes descargar el audio de las canciones en la calidad y formato que prefieras dandole click derecho a estas

## Bugs y mejoras
- Ahora al darle hacia atras en el reproductor estando en aleatorio te volver a la ultima cancion escuchada, no a una random
- Arreglado un bug que no dejaba invitar a la gente como colaboradora debido a "datos invalidos"
- Se ha mejorado el apartado visual de la tienda, añadiendo secciones dentro de cada pestaña para cada tipo de objeto
- Se ha optimizado en gran medida la API de llamadas y los procesos que se usaban para poder aguantar mas usuarios simultaneos
- Se ha optimizado el reproductor de la TV
