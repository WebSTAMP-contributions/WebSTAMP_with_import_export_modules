<div class="substep__title">
    System Safety Constraints
</div>

<div class="substep__add" data-component="add-button" data-add="systemsafetyconstraint">
    +
</div>

<div class="substep__content">
    <ul class="substep__list">
        @foreach (App\SystemSafetyConstraints::all() as $systemSafetyConstraint)
            <li class="item" id="systemsafetyconstraint-{{$systemSafetyConstraint->id}}">
                <div class="item__title">
                    SSC-{{$systemSafetyConstraint->id}}: {{ $systemSafetyConstraint->name }}
                </div>
                <div class="item__actions">
                    <div class="item__title">
                        <img src="{{ asset('images/edit.ico') }}" alt="Edit" width="20" class="navbar__logo">
                    </div>
                    <form action ="/deletesystemsafetyconstraint" method="POST" class="delete-form ajaxform" data-delete="systemsafetyconstraint">
                        <div class="item__title">
                            <input type="hidden" name="_token" value="{{csrf_token()}}">
                            <input id="project_id" name="project_id" type="hidden" value="1">
                            <input id="systemsafetyconstraint_id" name="systemsafetyconstraint_id" type="hidden" value="{{$systemSafetyConstraint->id}}">
                            <input type="image" src="{{ asset('images/delete.ico') }}" alt="Delete" width="20" class="navbar__logo">
                        </div>
                    </form>
                </div>
            </li>
        @endforeach
    </ul>
</div>