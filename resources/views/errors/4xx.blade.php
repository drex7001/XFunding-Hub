@include('errors.page', ['code' => $exception->getStatusCode(), 'heading' => 'This action is not available', 'text' => $exception->getMessage() ?: 'Refresh the page and try again.'])
