<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php $this->include('partials.marketplace', ['tab' => ($tab ?? 'apps')]); ?>
<?php $this->endSection(); ?>
