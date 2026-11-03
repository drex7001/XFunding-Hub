<x-layout title="Platform accounts">
    <x-page-header title="Platform accounts" meta="Operations staff never bid, select or settle on behalf of a bank." />

    <div class="grid-main">
        <section class="panel panel--flush">
            <div class="panel__head panel__head--flush"><h2>Operations staff</h2></div>
            @foreach ($records as $account)
                <div class="row row--flush">
                    <div class="row__text">
                        <strong>{{ $account->name }}</strong>
                        <small>{{ $account->email }}</small>
                    </div>
                    <x-status :value="$account->active ? 'active' : 'suspended'" :label="$account->active ? 'Active' : 'Inactive'" />
                    @can('changePlatformAccountStatus', $account)
                        <form class="row-form" method="post" action="{{ $account->active ? route('platform-accounts.deactivate', $account) : route('platform-accounts.reactivate', $account) }}">
                            @csrf
                            <div>
                                <label class="sr-only" for="reason-{{ $account->id }}">Reason</label>
                                <input id="reason-{{ $account->id }}" name="reason" placeholder="Reason" minlength="8" maxlength="500" required>
                            </div>
                            <button @class(['btn btn--sm', 'btn--danger' => $account->active, 'btn--secondary' => ! $account->active])>{{ $account->active ? 'Deactivate' : 'Reactivate' }}</button>
                        </form>
                    @endcan
                </div>
            @endforeach
            {{ $records->links() }}
        </section>
        <section class="panel">
            <h2>Add an operations account</h2>
            <p class="muted">The person sets a password through an emailed link. Every change is audited with its reason.</p>
            <form method="post" action="{{ route('platform-accounts.store') }}">
                @csrf
                <label for="name">Full name</label>
                <input id="name" name="name" value="{{ old('name') }}" maxlength="120" required>
                <label for="email">Work email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" required>
                <label for="reason">Reason</label>
                <input id="reason" name="reason" value="{{ old('reason') }}" minlength="8" maxlength="500" required>
                <div class="btn-row"><button class="btn btn--primary">Create account</button></div>
            </form>
        </section>
    </div>
</x-layout>
