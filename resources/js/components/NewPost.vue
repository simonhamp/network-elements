<template>
    <div>
        <h3>New Post</h3>

        <div class="form-group">
            <textarea class="form-control" v-model="content"></textarea>
        </div>

        <button class="btn btn-primary" v-on:click="send">Post</button>
    </div>
</template>

<script>
    export default {
        mounted() {

        },
        data() {
            return {
                content: ""
            }
        },
        methods: {
            send() {
                axios.post('/api/posts', {"content": this.content});
            }
        }
    }
</script>
