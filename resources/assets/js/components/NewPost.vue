<template>
    <div>
        <h3>New Post</h3>

        <div class="form-group">
            <textarea class="form-control"></textarea>
        </div>

        <button class="btn btn-primary">Post</button>
    </div>
</template>

<script>
    export default {
        mounted() {
            console.log('Component mounted.')
        }
    }
</script>
