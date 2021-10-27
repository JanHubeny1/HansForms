import Vue from "vue";
import VueRouter from "vue-router";
import Home from "../views/Home.vue";
import About from "../views/About.vue";
import Login from "../views/Login.vue";
import Register from "../views/Register.vue";

import store from "../store";
import Profile from "../views/Profile";
import Form from "../views/Form";

Vue.use(VueRouter);

const routes = [
    {
        path: "/",
        name: "Home",
        component: Home
    },
    {
        path: "/about",
        name: "About",
        component: About
    },
    {
        path: "/login",
        name: "Login",
        component: Login,
        meta: { requiresGuest: true }
    },
    {
        path: "/register",
        name: "Register",
        component: Register,
        meta: { requiresGuest: true }
    },
    {
        path: "/profile",
        name: "Profile",
        component: Profile,
        meta: { requiresAuth: true }
    },
    {
        path: "/form/:slug",
        name: "form",
        component: Form,
    },
];

const router = new VueRouter({
    mode: "history",
    base: process.env.BASE_URL,
    routes,
});

router.beforeEach((to, from, next) => {
    if (to.matched.some(record => record.meta.requiresGuest)) {
        if (store.getters['authenticated']) {
            next('/');
        } else {
            next();
        }
    }
    else if (to.matched.some(record => record.meta.requiresAuth)) {
        if (store.getters['authenticated']) {
            next();
        }
        else next('/login');
    }
    else {
        next();
    }
})

export default router;