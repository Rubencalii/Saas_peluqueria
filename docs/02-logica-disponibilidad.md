# 02 · Lógica de Disponibilidad

> La parte más delicada del proyecto. Si esto se hace mal, hay dobles reservas y huecos perdidos.

## 1. Principio general

La disponibilidad **no se almacena hueco a hueco**; se **calcula** a partir de:

```
Disponibilidad = Horario laboral del profesional
               − Citas ya existentes
               − Bloqueos (vacaciones, descansos, baja)
               − Margen del servicio (limpieza/preparación)
```

Esto evita inconsistencias y permite cambiar horarios sin recrear huecos.

## 2. Conceptos clave

- **Granularidad / slot base**: el sistema razona en intervalos fijos (p. ej. **15 min**). Una cita de 45 min ocupa 3 slots consecutivos.
- **Duración del servicio**: cada servicio define cuántos minutos ocupa.
- **Margen (buffer)**: minutos extra tras un servicio (limpieza, recoger). No es reservable.
- **Horario laboral**: por profesional, por sede y por día de la semana (con posibles turnos partidos: mañana + tarde).
- **Bloqueos**: rangos no disponibles (vacaciones, formación, comida no fija).

## 3. Algoritmo de cálculo de huecos (pseudocódigo)

```
función huecos_disponibles(sede, servicio, profesional, fecha):
    duración = servicio.duración + servicio.margen
    resultado = []

    profesionales = [profesional] si se eligió uno
                    else profesionales de la sede que ofrecen el servicio

    para cada prof en profesionales:
        turnos = horario_laboral(prof, sede, fecha)        # p.ej. 09:00-14:00 y 16:00-20:00
        ocupado = citas(prof, fecha) + bloqueos(prof, fecha)

        para cada turno en turnos:
            cursor = turno.inicio
            mientras cursor + duración <= turno.fin:
                intervalo = [cursor, cursor + duración]
                si intervalo NO solapa con nada en 'ocupado':
                    resultado.add({prof, hora: cursor})
                cursor = cursor + slot_base    # avanzar 15 min

    devolver agrupar_y_deduplicar(resultado)
```

**Notas**
- Si el cliente eligió "sin preferencia", se ofrecen los huecos de **cualquier** profesional que haga ese servicio; al confirmar se asigna uno concreto.
- Los huecos se calculan en la **zona horaria de la sede** y se almacenan en UTC.
- No ofrecer huecos en el pasado ni dentro de un margen mínimo de antelación (p. ej. "no se puede reservar para dentro de menos de 30 min").

## 4. Anti-doble-reserva (concurrencia)

Es el punto crítico: web y WhatsApp pueden intentar el **mismo hueco a la vez**.

**Reglas**
1. Mostrar disponibilidad es solo orientativo; **la verdad se decide al confirmar**.
2. La creación de la cita debe ser **atómica y transaccional**:
   - Abrir transacción.
   - Verificar **dentro de la transacción** que el intervalo sigue libre (sin solapamientos para ese profesional).
   - Si libre → insertar cita. Si no → error "hueco ya ocupado".
   - Cerrar transacción.
3. Usar bloqueo a nivel de BD para evitar la condición de carrera. Opciones:
   - **Restricción de exclusión** en PostgreSQL sobre el rango temporal por profesional (extensión `btree_gist` + `EXCLUDE USING gist`), que impide solapamientos a nivel de motor.
   - O bloqueo pesimista (`SELECT ... FOR UPDATE`) sobre las filas afectadas.
4. **Reserva temporal (hold) opcional**: mientras el cliente completa el formulario/conversación, se puede "retener" el hueco unos minutos (p. ej. 5) con un estado `pendiente` y expiración. Mejora la experiencia pero añade complejidad; opcional para fases posteriores.

> Recomendación: la **restricción de exclusión de PostgreSQL** es la defensa más robusta y simple de mantener. Aunque la lógica de aplicación falle, la base de datos rechaza el solapamiento.

## 5. Servicios con tiempos muertos (tintes, mechas, permanentes)

Caso muy típico de peluquería y fuente de ingresos perdidos si se ignora.

**Problema**: en un tinte, el profesional trabaja 20 min (aplicación), luego hay **30-40 min de reposo** en los que **podría atender a otra persona**, y luego 15 min más (lavado/peinado).

**Modelo recomendado**: definir el servicio como **segmentos**:

```
Servicio "Tinte":
  - Segmento 1: 20 min  (ACTIVO   → ocupa al profesional)
  - Segmento 2: 35 min  (ESPERA   → NO ocupa al profesional)
  - Segmento 3: 15 min  (ACTIVO   → ocupa al profesional)
```

Durante el segmento de ESPERA, el motor de disponibilidad considera al profesional **libre** y puede encajar otra cita corta. La silla/recurso, en cambio, puede seguir ocupada (ver recursos abajo).

**Decisión a tomar**: si esto entra en el MVP o no. Recomendado **sí**, porque cambia el modelo de datos (hay que prever segmentos desde el inicio). Si se deja fuera, modelar el servicio como un único bloque y aceptar la pérdida de eficiencia.

## 6. Recursos compartidos (opcional / avanzado)

Algunas sedes tienen recursos limitados: lavacabezas, sillas, aparatos. Si se quiere precisión, modelar **recursos** y que un servicio requiera ciertos recursos durante ciertos segmentos. Esto se puede dejar para una fase avanzada; en el MVP suele bastar con la agenda del profesional.

## 7. Cancelación y liberación de huecos

- Al cancelar una cita, su estado pasa a `cancelada` y el hueco vuelve a estar disponible automáticamente (porque la disponibilidad se calcula).
- Definir **política de antelación** para cancelar self-service (p. ej. hasta 2 h antes); fuera de plazo, derivar a la sede.
- Al reprogramar: crear la nueva cita (con su verificación atómica) y solo entonces cancelar la antigua, para no perder el hueco si la nueva falla.

## 8. Casos límite a contemplar

- Cambio de horario laboral con citas ya existentes en el hueco eliminado.
- Profesional que se da de baja teniendo citas futuras (reasignar o avisar).
- Cambio de horario de verano/invierno (gestionado por la zona horaria, no manual).
- Servicio cuya duración cambia después de haber citas reservadas (las existentes mantienen su duración original).
- Reservas a caballo entre dos turnos (no deben permitirse si no caben enteras en un turno).
