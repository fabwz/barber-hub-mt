<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Src\Identity\Users\Application\UseCases\ShowNoticePreferences;
use Src\Identity\Users\Application\UseCases\UpdateNoticePreferences;
use Src\Identity\Users\Infrastructure\Http\Requests\UpdateNoticePreferencesRequest;
use Src\Identity\Users\Infrastructure\Http\Resources\NoticePreferencesResource;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;

#[Group('Cuentas', 'Registro de cuentas de personas que usan BarberHub.')]
final readonly class NoticePreferencesController
{
    /** Show whether the authenticated person wants the reminders by email. */
    #[Endpoint(
        title: 'Ver mis preferencias de avisos',
        description: 'Devuelve `recordatorios_correo`: si la persona recibe por correo el recordatorio de 24 h y el de 2 h antes de sus reservas (RF-47). Es `true` hasta que la persona lo desactiva y aplica a todas sus reservas en todas las barberías. Es de la propia cuenta, sea cliente, colaborador o dueño, y no lleva barbería en la ruta. La confirmación, la oferta de espacio, la cancelación o reprogramación de la barbería, el reembolso y el restablecimiento de contraseña no se pueden desactivar (NO-10), por eso no figuran aquí. Una cuenta con contraseña temporal recibe 403 `contrasena-temporal` hasta que la cambia.',
    )]
    public function show(#[CurrentUser] UserModel $user, ShowNoticePreferences $showPreferences): NoticePreferencesResource
    {
        return new NoticePreferencesResource($showPreferences->execute($user->id));
    }

    /** Turn the reminders by email on or off. */
    #[Endpoint(
        title: 'Cambiar mis preferencias de avisos',
        description: 'Activa o desactiva los recordatorios por correo de la propia cuenta (RF-47, NO-02, NO-10). `recordatorios_correo` es obligatorio y debe ser un booleano (`true` o `false`, no `"1"` ni `1`); no se lee nada más del cuerpo. Responde 422 `datos-invalidos` con `errores.recordatorios_correo` si falta o no es booleano. Desactivarlos detiene los recordatorios de las reservas nuevas y de las ya hechas, pero no toca la confirmación ni la oferta de espacio. Reactivarlos no los devuelve a las reservas hechas mientras estaban desactivados: solo a las que se hagan o reprogramen desde entonces. Una cuenta con contraseña temporal recibe 403 `contrasena-temporal` hasta que la cambia.',
    )]
    public function update(UpdateNoticePreferencesRequest $request, #[CurrentUser] UserModel $user, UpdateNoticePreferences $updatePreferences): NoticePreferencesResource
    {
        return new NoticePreferencesResource($updatePreferences->execute($user->id, $request->remindersEnabled()));
    }
}
